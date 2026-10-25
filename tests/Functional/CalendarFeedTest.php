<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Repository\PriceHistoryRepository;
use App\Security\Scope;
use App\Service\CalendarFeedService;
use DateTimeImmutable;

/**
 * The iCalendar feed: what it contains, and who may fetch it.
 *
 * The authentication half matters as much as the contents. This is the one
 * endpoint that takes its credential in the URL, because a calendar client
 * cannot send a header, and a URL-borne credential ends up in proxy logs and
 * browser history. The compensating rule is that it accepts read-only tokens
 * and nothing else, enforced by which middleware the route is given rather than
 * by a condition some later route could be added on the wrong side of.
 */
final class CalendarFeedTest extends ApiTestCase
{
    public function testAFeedNeedsAToken(): void
    {
        self::assertSame(401, $this->api('GET', '/api/v1/calendar.ics')->getStatusCode());
        self::assertSame(401, $this->api('GET', '/api/v1/calendar.ics?token=rubbish')->getStatusCode());
    }

    public function testAWriteCapableTokenIsRefusedRatherThanDowngraded(): void
    {
        $response = $this->api('GET', '/api/v1/calendar.ics?token=' . $this->ownerToken);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testABearerHeaderIsNotAcceptedHere(): void
    {
        // The feed route is wired to the query-parameter middleware only, so a
        // header carries no weight on it. Belt and braces: it proves the two
        // middlewares really are separate.
        self::assertSame(
            401,
            $this->api('GET', '/api/v1/calendar.ics', $this->readOnlyToken)->getStatusCode(),
        );
    }

    public function testTheFeedIsAWellFormedCalendar(): void
    {
        $this->createSubscription(['name' => 'Streaming', 'next_payment_date' => '2026-12-01']);

        $response = $this->api('GET', '/api/v1/calendar.ics?token=' . $this->readOnlyToken);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/calendar', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

        $body = (string) $response->getBody();

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $body);
        self::assertStringContainsString('VERSION:2.0', $body);

        // Every line is CRLF-terminated, and none exceeds the 75-octet limit
        // before folding. Clients are forgiving about both until one is not.
        foreach (explode("\r\n", rtrim($body, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), sprintf('Line too long: %s', $line));
        }

        self::assertSame(
            substr_count($body, 'BEGIN:VEVENT'),
            substr_count($body, 'END:VEVENT'),
            'Every VEVENT must be closed.',
        );
    }

    public function testRenewalsAppearAsWholeDayEvents(): void
    {
        $this->createSubscription(['name' => 'Streaming', 'next_payment_date' => '2026-12-01']);

        $body = $this->feed();

        self::assertStringContainsString('DTSTART;VALUE=DATE:20261201', $body);
        // DTEND is exclusive, so a one-day event ends the following day. Give a
        // client the same date at both ends and it displays nothing at all.
        self::assertStringContainsString('DTEND;VALUE=DATE:20261202', $body);
        self::assertStringContainsString('Streaming', $body);
    }

    public function testAFixedTermsPaymentsStopAtItsLastPayment(): void
    {
        $first = new \DateTimeImmutable('first day of next month');
        $second = $first->modify('+1 month');
        $this->createSubscription([
            'name' => 'Phone finance',
            'next_payment_date' => $first->format('Y-m-d'),
            'payments_end_on' => $second->modify('+14 days')->format('Y-m-d'),
        ]);

        $body = $this->feed();

        self::assertStringContainsString('DTSTART;VALUE=DATE:' . $first->format('Ymd'), $body);
        self::assertStringContainsString('DTSTART;VALUE=DATE:' . $second->format('Ymd'), $body);
        self::assertStringNotContainsString(
            'DTSTART;VALUE=DATE:' . $second->modify('+1 month')->format('Ymd'),
            $body,
        );
        self::assertSame(2, substr_count($this->unfold($body), 'SUMMARY:Phone finance'));
    }

    public function testNoCancelByIsPublishedAfterAFixedTermsLastPayment(): void
    {
        // Its stored date is already past the last payment (nobody caught it
        // up), so its notice deadline is for a charge that will not happen.
        $id = $this->createSubscription([
            'name' => 'Phone finance',
            'next_payment_date' => (new \DateTimeImmutable('+20 days'))->format('Y-m-d'),
            'payments_end_on' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'notice_period_amount' => 10,
            'notice_period_unit' => 'days',
        ]);
        $this->db->execute(
            'UPDATE subscriptions SET next_payment_date = :date WHERE id = :id',
            ['date' => (new \DateTimeImmutable('+40 days'))->format('Y-m-d'), 'id' => $id],
        );

        $body = $this->unfold($this->feed());

        self::assertStringNotContainsString('Last day to cancel Phone finance', $body);
        self::assertStringNotContainsString('SUMMARY:Phone finance', $body);
    }

    public function testATrialConversionAppears(): void
    {
        $this->createSubscription([
            'name' => 'Trial service',
            'is_trial' => true,
            'trial_end_date' => '2026-11-15',
            'converts_to_price_minor' => 1500,
            'next_payment_date' => null,
        ]);

        $body = $this->feed();

        self::assertStringContainsString('20261115', $body);
        self::assertStringContainsString('trial ends', $this->unfold($body));
    }

    public function testACancelByDeadlineAppears(): void
    {
        // A month's notice on a payment due on the 1st of December means the
        // last useful day to act is the 1st of November — the fact this feed
        // exists to put in somebody's diary.
        $this->createSubscription([
            'name' => 'Gym',
            'next_payment_date' => '2026-12-01',
            'notice_period_amount' => 1,
            'notice_period_unit' => 'months',
        ]);

        $body = $this->unfold($this->feed());

        self::assertStringContainsString('Last day to cancel Gym', $body);
        self::assertStringContainsString('20261101', $body);
    }

    public function testEventIdentifiersAreStableAcrossFetches(): void
    {
        // A calendar client refetches on a timer. Unstable ids would mean a
        // duplicate of every event, every time.
        $this->createSubscription(['name' => 'Streaming', 'next_payment_date' => '2026-12-01']);

        self::assertSame(
            $this->uids($this->feed()),
            $this->uids($this->feed()),
        );
    }

    public function testTextIsEscapedRatherThanBreakingTheFormat(): void
    {
        // A comma and a semicolon are field separators in iCalendar. A name
        // containing one would otherwise truncate the summary or corrupt the
        // event.
        $this->createSubscription([
            'name' => 'Books, papers; and things',
            'next_payment_date' => '2026-12-01',
        ]);

        $body = $this->unfold($this->feed());

        self::assertStringContainsString('Books\\, papers\; and things', $body);
    }

    public function testPaymentsCarryTheScheduledPrice(): void
    {
        $first = new DateTimeImmutable('first day of next month');
        $rise = $first->modify('+1 month');
        $id = $this->createSubscription([
            'name' => 'Streaming',
            'price_minor' => 1000,
            'next_payment_date' => $first->format('Y-m-d'),
        ]);
        $this->announce($id, 1500, $rise);

        $body = $this->unfold($this->feed());

        self::assertStringContainsString('10.00', $this->summaryOn($body, $first));
        self::assertStringContainsString('15.00', $this->summaryOn($body, $rise));
        self::assertStringContainsString('15.00', $this->summaryOn($body, $rise->modify('+1 month')));
    }

    public function testAnOfferEndShowsTheFullPrice(): void
    {
        $first = new DateTimeImmutable('first day of next month');
        $ends = $first->modify('+2 months');
        $id = $this->createSubscription([
            'name' => 'Streaming',
            'price_minor' => 300,
            'next_payment_date' => $first->format('Y-m-d'),
        ]);
        $this->db->execute('UPDATE subscription_price_history SET is_promotional = :on WHERE subscription_id = :id', [
            'on' => true,
            'id' => $id,
        ]);
        $this->announce($id, 900, $ends);

        $body = $this->unfold($this->feed());

        self::assertStringContainsString('3.00', $this->summaryOn($body, $first->modify('+1 month')));
        self::assertStringContainsString('9.00', $this->summaryOn($body, $ends));
    }

    public function testTheFeedTextIsUnchanged(): void
    {
        $this->createSubscription(['name' => 'Streaming', 'next_payment_date' => '2026-12-01']);

        $lines = explode("\r\n", $this->unfold($this->feed()));

        self::assertSame('BEGIN:VCALENDAR', $lines[0]);
        self::assertMatchesRegularExpression('/^UID:renovo-\\d+-payment-20261201@renovo\\.local$/m', implode("\n", $lines));
        self::assertContains('DTSTART;VALUE=DATE:20261201', $lines);
        self::assertContains('DTEND;VALUE=DATE:20261202', $lines);
        self::assertContains('TRANSP:TRANSPARENT', $lines);
        self::assertSame('END:VCALENDAR', $lines[count($lines) - 2]);
        self::assertSame('', $lines[count($lines) - 1]);
    }

    public function testALargeFeedIsBuiltInLinearTime(): void
    {
        $service = $this->container()->get(CalendarFeedService::class);
        $scope = Scope::forMember(
            $this->ownerId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            $this->isolationMode(),
        );
        for ($i = 0; $i < 120; $i++) {
            $this->createSubscription([
                'name' => 'Weekly ' . $i,
                'billing_cycle' => 'weekly',
                'next_payment_date' => '2026-12-01',
            ]);
        }

        $started = microtime(true);
        $feed = $service->build($scope, 'Renovo');
        $elapsed = microtime(true) - $started;

        self::assertGreaterThan(4000, substr_count($feed, 'BEGIN:VEVENT'));
        self::assertLessThan(5.0, $elapsed);
    }

    private function announce(int $subscriptionId, int $priceMinor, DateTimeImmutable $from): void
    {
        $scope = Scope::forMember(
            $this->ownerId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            $this->isolationMode(),
        );
        $this->container()->get(PriceHistoryRepository::class)->append(
            $scope,
            $subscriptionId,
            Money::of($priceMinor, 'GBP'),
            $from,
            PriceChangeSource::Scheduled,
        );
    }

    /**
     * The summary of the payment event on a date, or '' when there is none.
     */
    private function summaryOn(string $unfolded, DateTimeImmutable $date): string
    {
        $pattern = '/DTSTART;VALUE=DATE:' . $date->format('Ymd') . '\r\n.*?SUMMARY:([^\r\n]*)/s';

        return preg_match($pattern, $unfolded, $m) === 1 ? $m[1] : '';
    }

    private function feed(): string
    {
        return (string) $this->api('GET', '/api/v1/calendar.ics?token=' . $this->readOnlyToken)->getBody();
    }

    /**
     * Undo the 75-octet folding so assertions can look at whole values.
     */
    private function unfold(string $body): string
    {
        return str_replace("\r\n ", '', $body);
    }

    /**
     * @return list<string>
     */
    private function uids(string $body): array
    {
        preg_match_all('/^UID:(.+)$/m', $this->unfold($body), $matches);

        $uids = array_map(static fn (string $uid): string => trim($uid), $matches[1]);
        sort($uids);

        return $uids;
    }
}
