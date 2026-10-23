<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\BackupRunRepository;
use App\Repository\HouseholdRepository;
use App\Repository\HttpMetricsRepository;
use App\Repository\MigrationRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;

/**
 * The Prometheus exposition, rendered by hand.
 *
 * No client library: the format is six lines of specification — a HELP line, a
 * TYPE line, and `name{labels} value` — and a dependency that pulls in a
 * registry, a storage adapter and a locking strategy to produce it would be
 * more code in the vendor directory than there is in this file.
 *
 * Every number here is an aggregate. There is no metric labelled by household,
 * user or subscription name, and that is deliberate: a metrics endpoint ends
 * up in a dashboard somebody shares, and "which subscriptions does this
 * household have" is exactly what this application is for keeping.
 */
final class MetricsService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly HouseholdRepository $households,
        private readonly SubscriptionRepository $subscriptions,
        private readonly NotificationLogRepository $notifications,
        private readonly MigrationRepository $migrations,
        private readonly HttpMetricsRepository $http,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly BackupRunRepository $backups,
    ) {
    }

    public function render(): string
    {
        $subscriptions = $this->subscriptions->instanceTotals();
        $notifications = $this->notifications->instanceTotals();
        $lastRun = $this->settings->schedulerLastRunAt();
        $lastRefresh = $this->rates->lastRefreshedAt();

        $lines = [];

        $this->gauge($lines, 'renovo_up', 'Whether the application answered this scrape.', 1);

        $this->gauge($lines, 'renovo_users_total', 'Accounts on this instance.', $this->users->countAll());
        $this->gauge($lines, 'renovo_households_total', 'Households on this instance.', $this->households->countAll());

        $lines[] = '# HELP renovo_subscriptions_total Subscriptions, by whether they are active.';
        $lines[] = '# TYPE renovo_subscriptions_total gauge';
        $lines[] = sprintf('renovo_subscriptions_total{state="active"} %d', $subscriptions['active']);
        $lines[] = sprintf('renovo_subscriptions_total{state="inactive"} %d', $subscriptions['inactive']);

        $this->gauge(
            $lines,
            'renovo_subscriptions_in_trial',
            'Subscriptions currently in a free trial.',
            $subscriptions['trials'],
        );

        $lines[] = '# HELP renovo_notifications_total Notification deliveries recorded, by outcome.';
        $lines[] = '# TYPE renovo_notifications_total counter';
        foreach (['sent', 'failed', 'pending'] as $status) {
            $lines[] = sprintf('renovo_notifications_total{status="%s"} %d', $status, $notifications[$status]);
        }

        $this->gauge(
            $lines,
            'renovo_scheduler_last_run_timestamp_seconds',
            'When the scheduler last finished a run. Zero when it never has.',
            $lastRun?->getTimestamp() ?? 0,
        );

        $this->gauge(
            $lines,
            'renovo_exchange_rates_last_refresh_timestamp_seconds',
            'When exchange rates were last cached. Zero when they never have been.',
            $lastRefresh?->getTimestamp() ?? 0,
        );

        $this->gauge(
            $lines,
            'renovo_exchange_rates_stale',
            'Whether the cached exchange rates are past their refresh window.',
            $this->rates->isStale() ? 1 : 0,
        );

        $this->gauge(
            $lines,
            'renovo_exchange_rates_currencies',
            'Currencies in the cached rate table.',
            count($this->rates->availableCurrencies()),
        );

        $this->gauge(
            $lines,
            'renovo_migrations_applied_total',
            'Migrations recorded as applied.',
            $this->migrations->appliedCount(),
        );

        $this->backups($lines);

        $this->http($lines);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $lines
     */
    private function http(array &$lines): void
    {
        $buckets = $this->http->all();

        $lines[] = '# HELP renovo_http_requests_total Requests served, by response status class.';
        $lines[] = '# TYPE renovo_http_requests_total counter';

        $lines[] = '# HELP renovo_http_request_duration_seconds_sum Time spent serving them, in seconds.';
        $lines[] = '# TYPE renovo_http_request_duration_seconds_sum counter';

        // Every class is emitted even at zero: a counter that appears only
        // once it is non-zero is one a dashboard cannot draw until something
        // has gone wrong.
        foreach (['2xx', '3xx', '4xx', '5xx', 'other'] as $bucket) {
            $entry = $buckets[$bucket] ?? ['requests' => 0, 'duration_us' => 0];

            $lines[] = sprintf('renovo_http_requests_total{status="%s"} %d', $bucket, $entry['requests']);
            $lines[] = sprintf(
                'renovo_http_request_duration_seconds_sum{status="%s"} %s',
                $bucket,
                number_format($entry['duration_us'] / 1_000_000, 6, '.', ''),
            );
        }
    }

    /**
     * Instance backups (Phase 38): when the last good one finished, how big
     * it was, and how many runs have ended each way. Both outcomes are
     * emitted at zero, so an alert on failures can be written before the
     * first one.
     *
     * @param list<string> $lines
     */
    private function backups(array &$lines): void
    {
        $last = $this->backups->lastVerified();

        $this->gauge(
            $lines,
            'renovo_backup_last_success_timestamp_seconds',
            'When the last verified instance backup finished. Zero when none has.',
            ($last->finishedAt ?? $last?->startedAt)?->getTimestamp() ?? 0,
        );

        $this->gauge(
            $lines,
            'renovo_backup_last_size_bytes',
            'The size of the last verified instance backup, encrypted.',
            $last->sizeBytes ?? 0,
        );

        $lines[] = '# HELP renovo_backup_runs_total Instance backup runs, by outcome.';
        $lines[] = '# TYPE renovo_backup_runs_total counter';
        foreach ($this->backups->countsByStatus() as $status => $count) {
            $lines[] = sprintf('renovo_backup_runs_total{status="%s"} %d', $status, $count);
        }
    }

    /**
     * @param list<string> $lines
     */
    private function gauge(array &$lines, string $name, string $help, int $value): void
    {
        $lines[] = '# HELP ' . $name . ' ' . $help;
        $lines[] = '# TYPE ' . $name . ' gauge';
        $lines[] = $name . ' ' . $value;
    }
}
