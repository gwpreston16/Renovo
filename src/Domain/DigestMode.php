<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Whether alerts go out as they arise or are collected up.
 *
 * Digest mode is not a filter — nothing is dropped. It changes when the same
 * alerts are delivered and bundles them into one message, which for somebody
 * tracking thirty subscriptions is the difference between a useful summary and
 * a stream of individual pings.
 *
 * In digest mode lead times stop applying. A weekly digest that also honoured a
 * 30/7/1 schedule would announce the same renewal in three consecutive digests;
 * instead each digest covers the window up to the next one, so everything is
 * mentioned exactly once, shortly before it happens.
 */
enum DigestMode: string
{
    case Immediate = 'immediate';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function isDigest(): bool
    {
        return $this !== self::Immediate;
    }

    public function labelKey(): string
    {
        return 'digest_mode.' . $this->value;
    }

    /**
     * How far back a digest's price-change look-back reaches, in days. The
     * look-ahead is `windowDays()`, which ends before the next issue.
     */
    public function horizonDays(): int
    {
        return match ($this) {
            self::Immediate => 0,
            self::Weekly => 7,
            self::Monthly => 31,
        };
    }

    /**
     * How many days an issue sent today covers: today up to, but not including,
     * the day its successor goes out, so a renewal is in exactly one issue.
     *
     * Weekly is always seven. Monthly follows the calendar — today through the
     * day before `$day` of next month — so February's issue is shorter than
     * January's and never reaches the March issue's first day.
     */
    public function windowDays(DateTimeImmutable $today, int $day): int
    {
        return match ($this) {
            self::Immediate => 0,
            self::Weekly => 7,
            self::Monthly => (int) $today->setTime(0, 0)->diff($this->nextIssue($today, $day))->days,
        };
    }

    private function nextIssue(DateTimeImmutable $today, int $day): DateTimeImmutable
    {
        $next = $today->modify('first day of next month');

        return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $day)->setTime(0, 0);
    }

    /**
     * Whether today is the day this digest goes out.
     *
     * Weekly reads `$day` as an ISO weekday (1 = Monday). Monthly reads it as a
     * day of the month, which the preference service caps at 28 so that a
     * digest cannot be scheduled for a date February does not have.
     */
    public function isDueOn(DateTimeImmutable $today, int $day): bool
    {
        return match ($this) {
            self::Immediate => false,
            self::Weekly => (int) $today->format('N') === $day,
            self::Monthly => (int) $today->format('j') === $day,
        };
    }

    /**
     * The key identifying this issue of the digest, which is what stops a
     * second scheduler run on the same day sending it again.
     */
    public function periodKey(DateTimeImmutable $today): string
    {
        return match ($this) {
            self::Immediate => $today->format('Y-m-d'),
            self::Weekly => $today->format('o-\WW'),
            self::Monthly => $today->format('Y-m'),
        };
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
