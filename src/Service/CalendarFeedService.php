<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Security\Scope;
use App\Support\Clock;
use App\Support\MoneyFormatter;
use DateTimeImmutable;

/**
 * The renewal calendar, as an iCalendar feed.
 *
 * Read-only by construction — it emits text and touches nothing — and that is
 * what makes it safe to hand to a calendar client that will fetch it
 * unattended for years.
 *
 * Three kinds of event, because three different things are worth a reminder in
 * a diary: the next payments, the day a trial turns into a bill, and the last
 * day to cancel before a notice period makes that impossible. The third is the
 * one a calendar is genuinely better at than a notification — it is a deadline,
 * not an event, and seeing it a fortnight out is the entire point.
 *
 * Every event is a whole-day event with no time zone. A billing date is a date,
 * not an instant; giving it a time would move it across midnight for somebody.
 */
final class CalendarFeedService
{
    /**
     * How far ahead recurring payments are projected. A year covers an annual
     * subscription exactly once, which is the shortest horizon that shows every
     * subscription at least once.
     */
    private const HORIZON_DAYS = 365;

    /**
     * A ceiling on occurrences per subscription, so a daily custom cycle cannot
     * produce a feed thousands of events long.
     */
    private const MAX_OCCURRENCES = 60;

    private const PRODUCT_ID = '-//Renovo//Subscription calendar//EN';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly ForecastService $forecast,
        private readonly MoneyFormatter $money,
        private readonly Translator $translator,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Build the feed for everything this scope can see.
     *
     * Note that it asks the service for the scoped list rather than for "all
     * subscriptions": the feed is exactly as isolated as the list page, because
     * it is the same query.
     */
    public function build(Scope $scope, string $instanceName): string
    {
        $today = $this->clock->today();
        $horizon = $today->modify('+' . self::HORIZON_DAYS . ' days');
        $stamp = $this->clock->now()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODUCT_ID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escape(
                $this->translator->trans('calendar.feed_name', ['instance' => $instanceName]),
            ),
            'X-WR-CALDESC:' . $this->escape($this->translator->trans('calendar.feed_description')),
        ];

        $charges = $this->chargesBySubscription($scope);

        // Lines are collected into one list per event and merged once at the
        // end: merging into the accumulated list per event is quadratic.
        $rendered = [$lines];
        foreach ($this->subscriptions->allForStats($scope, activeOnly: true) as $subscription) {
            $events = $this->eventsFor($subscription, $today, $horizon, $charges[$subscription->id] ?? []);
            foreach ($events as $event) {
                $rendered[] = $this->renderEvent($event, $stamp);
            }
        }
        $rendered[] = ['END:VCALENDAR'];

        // RFC 5545 requires CRLF, and long lines folded. Clients are forgiving
        // about both right up until one is not.
        $lines = array_merge(...$rendered);

        return implode("\r\n", array_merge(...array_map($this->fold(...), $lines))) . "\r\n";
    }

    /**
     * The forecast's charges, grouped by subscription.
     *
     * Payments come from the forecast rather than a walk of billing dates at
     * today's price, so a scheduled rise or the end of an offer shows its new
     * amount on the day it takes effect, and the dates agree with the in-app
     * calendar. The forecast reads through the same scope, so the feed is as
     * isolated as the list page.
     *
     * @return array<int, list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>>
     */
    private function chargesBySubscription(Scope $scope): array
    {
        $grouped = [];
        foreach ($this->forecast->charges($scope) as $charge) {
            $grouped[$charge['subscription']->id][] = $charge;
        }

        return $grouped;
    }

    /**
     * @param list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}> $charges
     * @return list<array{uid: string, date: DateTimeImmutable, summary: string, description: string}>
     */
    private function eventsFor(
        Subscription $subscription,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
        array $charges,
    ): array {
        $events = [];
        $price = $this->money->format($subscription->price);

        if ($subscription->isTrial && $subscription->trialEndDate !== null) {
            if ($subscription->trialEndDate >= $today && $subscription->trialEndDate <= $horizon) {
                $converts = $this->money->format($subscription->priceAfterConversion());
                $events[] = [
                    'uid' => $this->uid($subscription->id, 'trial', $subscription->trialEndDate),
                    'date' => $subscription->trialEndDate,
                    'summary' => $this->translator->trans('calendar.trial_summary', [
                        'name' => $subscription->name,
                        'amount' => $converts,
                    ]),
                    'description' => $this->translator->trans('calendar.trial_description', [
                        'name' => $subscription->name,
                        'amount' => $converts,
                    ]),
                ];
            }
        }

        foreach ($this->payments($subscription, $today, $horizon, $charges) as $payment) {
            $amount = $this->money->format($payment['amount']);
            $events[] = [
                'uid' => $this->uid($subscription->id, 'payment', $payment['date']),
                'date' => $payment['date'],
                'summary' => $this->translator->trans('calendar.payment_summary', [
                    'name' => $subscription->name,
                    'amount' => $amount,
                ]),
                'description' => $this->translator->trans('calendar.payment_description', [
                    'name' => $subscription->name,
                    'amount' => $amount,
                ]),
            ];
        }

        // A deadline is for a charge still to come; after a fixed term's
        // last payment there is none to give notice against.
        $deadline = $subscription->cancellationDeadline();
        $charge = $subscription->nextChargeDate();
        if (
            $deadline !== null
            && $deadline >= $today
            && $deadline <= $horizon
            && ($charge === null || $subscription->chargesOn($charge))
        ) {
            $events[] = [
                'uid' => $this->uid($subscription->id, 'cancel-by', $deadline),
                'date' => $deadline,
                'summary' => $this->translator->trans('calendar.cancel_summary', ['name' => $subscription->name]),
                'description' => $this->translator->trans('calendar.cancel_description', [
                    'name' => $subscription->name,
                    'amount' => $price,
                ]),
            ];
        }

        return $events;
    }

    /**
     * Every payment between today and the horizon, at the amount that will be
     * taken.
     *
     * A trial that has not converted yet contributes none: it has no payment
     * date, and inventing one would put a charge in somebody's diary that is
     * not going to happen. Its conversion is already an event of its own.
     *
     * @param list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}> $charges
     * @return list<array{date: DateTimeImmutable, amount: Money}>
     */
    private function payments(
        Subscription $subscription,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
        array $charges,
    ): array {
        if ($subscription->isTrialActiveOn($today)) {
            return [];
        }

        $payments = [];
        foreach ($charges as $charge) {
            if ($charge['date'] > $horizon || count($payments) >= self::MAX_OCCURRENCES) {
                break;
            }
            $payments[] = ['date' => $charge['date'], 'amount' => $charge['amount']];
        }

        return $payments;
    }

    /**
     * @param array{uid: string, date: DateTimeImmutable, summary: string, description: string} $event
     * @return list<string>
     */
    private function renderEvent(array $event, string $stamp): array
    {
        return [
            'BEGIN:VEVENT',
            'UID:' . $event['uid'],
            'DTSTAMP:' . $stamp,
            'DTSTART;VALUE=DATE:' . $event['date']->format('Ymd'),
            // An all-day event ends on the following day: DTEND is exclusive,
            // and clients that are given the same date show nothing at all.
            'DTEND;VALUE=DATE:' . $event['date']->modify('+1 day')->format('Ymd'),
            'SUMMARY:' . $this->escape($event['summary']),
            'DESCRIPTION:' . $this->escape($event['description']),
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ];
    }

    /**
     * A stable identifier for one occurrence.
     *
     * Stable is the operative word: a client that refetches the feed must
     * recognise an event it already has rather than duplicating it, so the id
     * is derived from the subscription, the kind and the date, and never from
     * anything that changes between fetches.
     */
    private function uid(int $subscriptionId, string $kind, DateTimeImmutable $date): string
    {
        return sprintf('renovo-%d-%s-%s@renovo.local', $subscriptionId, $kind, $date->format('Ymd'));
    }

    /**
     * Escape the four characters iCalendar reserves in a text value.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\;', '\\,'],
            $value,
        );
    }

    /**
     * Fold a line to 75 octets, continuing with a leading space.
     *
     * Measured in octets rather than characters, and split on character
     * boundaries: folding mid-UTF-8-sequence produces a file that some clients
     * reject and others render as mojibake.
     *
     * @return list<string>
     */
    private function fold(string $line): array
    {
        if (strlen($line) <= 75) {
            return [$line];
        }

        $folded = [];
        $current = '';
        $limit = 75;

        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (strlen($current) + strlen($character) > $limit) {
                $folded[] = $current;
                // Continuation lines carry a leading space, which counts.
                $current = ' ';
                $limit = 75;
            }

            $current .= $character;
        }

        if ($current !== '' && $current !== ' ') {
            $folded[] = $current;
        }

        return $folded;
    }
}
