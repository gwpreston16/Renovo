<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\PriceChangeSource;
use App\Persistence\Database;
use App\Repository\SubscriptionRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Free trials, and the moment they stop being free.
 *
 * The timing rule, stated once here because everything else follows from it:
 * **the trial's last day is the day the conversion charge falls.** A trial
 * ending on the 30th is free up to and including the 30th, and the first
 * payment is dated the 30th. Treating the end date as exclusive would put the
 * charge a day late and tell the user they had one more free day than they do,
 * which is the wrong direction to be wrong in.
 *
 * Conversion keeps the same subscription. The row does not end and get replaced
 * — it changes price, and that change is recorded in the price history like any
 * other, so the trend shows the step from nothing to something and says what
 * caused it.
 */
final class TrialService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PriceHistoryService $priceHistory,
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Trials ending in the next $days days, soonest first.
     *
     * @return list<Subscription>
     */
    public function endingSoon(Scope $scope, int $days): array
    {
        $today = $this->clock->today();

        return $this->subscriptions->findTrialsEnding($scope, $today, $today->modify(sprintf('+%d days', $days)));
    }

    /**
     * Convert every trial whose end date has passed.
     *
     * Runs as part of the catch-up, after due price changes and before payment
     * dates are advanced — a trial that converted today produces a payment due
     * today, and the advance is what rolls it forward if it is already behind.
     *
     * @return int Number of trials converted.
     *
     * @phpstan-impure
     */
    public function convertDueTrials(Scope $scope): int
    {
        if (!$scope->canWrite()) {
            // As everywhere else in the catch-up: a Viewer's page load performs
            // no writes, and the conversion happens when somebody who can write
            // next looks.
            return 0;
        }

        $today = $this->clock->today();
        $converted = 0;

        foreach ($this->subscriptions->findTrialsToConvert($scope, $today) as $trial) {
            $this->convert($scope, $trial);
            $converted++;
        }

        return $converted;
    }

    /**
     * Apply one conversion.
     *
     * The price change and the flag change are one transaction: a subscription
     * that was marked converted but kept its trial price would under-report
     * every total on the dashboard, and one whose price changed while it still
     * looked like a trial would be converted again on the next page load.
     */
    private function convert(Scope $scope, Subscription $trial): void
    {
        $trialEnd = $trial->trialEndDate;
        if ($trialEnd === null) {
            return;
        }

        $price = $trial->priceAfterConversion();
        $cycle = $trial->billingCycleAfterConversion();
        $cycleDays = $trial->cycleDaysAfterConversion();
        $interval = $trial->cycleIntervalAfterConversion();

        $this->db->transactional(function () use (
            $scope,
            $trial,
            $trialEnd,
            $price,
            $cycle,
            $cycleDays,
            $interval,
        ): void {
            $this->subscriptions->convertTrial($scope, $trial->id, $cycle, $cycleDays, $interval, $trialEnd);

            // Dated to the trial's last day, not to today. If nobody opened the
            // application for a fortnight, the conversion still happened when
            // it happened, and the history has to say so.
            $this->priceHistory->recordCurrentPrice(
                $scope,
                $trial->id,
                $price,
                PriceChangeSource::TrialConversion,
                $trial->ownerUserId,
                'Free trial ended.',
                $trialEnd,
            );
        });
    }

    /**
     * The cost a converting trial will add, grouped by currency, for the
     * dashboard's "about to start costing money" figure.
     *
     * @param list<Subscription> $trials
     * @return list<array{currency: string, total_minor: int, count: int}>
     */
    public function conversionTotals(array $trials): array
    {
        $totals = [];

        foreach ($trials as $trial) {
            $price = $trial->priceAfterConversion();
            $currency = $price->currency;
            $totals[$currency] ??= ['currency' => $currency, 'total_minor' => 0, 'count' => 0];
            $totals[$currency]['total_minor'] += $price->amountMinor;
            $totals[$currency]['count']++;
        }

        ksort($totals);

        return array_values($totals);
    }

    /**
     * The date a trial converts, which is its last day.
     */
    public function conversionDate(Subscription $trial): ?DateTimeImmutable
    {
        return $trial->isTrial ? $trial->trialEndDate : null;
    }
}
