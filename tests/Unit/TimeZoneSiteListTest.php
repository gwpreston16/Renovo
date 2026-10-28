<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\TimeZoneSites;
use PHPUnit\Framework\TestCase;

/**
 * The list of date-parsing sites (Phase 63, K68) cannot go stale: a new site
 * in `src/`, or one that moves class, fails here until a person has looked at
 * it and refreshed the list with `bin/time-zone-sites --write`.
 */
final class TimeZoneSiteListTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testTheSiteListMatchesTheSource(): void
    {
        $listed = file($this->root . '/tests/fixtures/time-zone-sites.tsv', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($listed);

        $found = TimeZoneSites::lines(TimeZoneSites::scan($this->root));

        self::assertSame(
            [],
            array_values(array_diff($found, $listed)),
            'A date is parsed in src/ that the list does not know. Check its class, then run '
            . 'bin/time-zone-sites --write.',
        );
        self::assertSame(
            [],
            array_values(array_diff($listed, $found)),
            'The list names a site that is gone or has changed class. Run bin/time-zone-sites --write.',
        );
        // Same lines, same number of each: one of two identical lines may have changed class.
        sort($listed);
        self::assertSame($listed, $found);
    }

    public function testEverySiteIsInOneKnownClass(): void
    {
        foreach (TimeZoneSites::scan($this->root) as $site) {
            self::assertContains($site['class'], TimeZoneSites::CLASSES, $site['file'] . ':' . $site['line']);
        }
    }

    public function testTheScanFindsTheK68Sites(): void
    {
        $byLine = [];
        foreach (TimeZoneSites::scan($this->root) as $site) {
            $byLine[$site['file']][] = $site['class'];
        }

        // The sites K68 names in the known-issues log.
        self::assertContains(TimeZoneSites::DATE_ONLY, $byLine['Repository/PriceHistoryRepository.php']);
        self::assertContains(TimeZoneSites::USER_FACING, $byLine['Repository/SessionRepository.php']);
    }

    public function testCommentsAndANowAreNotSites(): void
    {
        $sites = TimeZoneSites::scan($this->root);
        $codes = array_column($sites, 'code');

        self::assertSame([], array_filter($codes, static fn (string $code): bool => str_starts_with($code, '*')));
        self::assertSame([], array_filter($codes, static fn (string $code): bool => str_contains($code, "('now'")));
    }

    public function testASiteIsPlacedByItsLineAndItsFile(): void
    {
        self::assertSame(
            TimeZoneSites::DATE_ONLY,
            TimeZoneSites::classify('Service/X.php', "DateTimeImmutable::createFromFormat('!Y-m-d', \$value)"),
        );
        self::assertSame(
            TimeZoneSites::ONE_ZONE,
            TimeZoneSites::classify('Service/X.php', "new DateTimeImmutable(\$month . '-01')"),
        );
        self::assertSame(
            TimeZoneSites::USER_FACING,
            TimeZoneSites::classify('Repository/SessionRepository.php', 'new DateTimeImmutable($x)'),
        );
        self::assertSame(
            TimeZoneSites::TIMESTAMP,
            TimeZoneSites::classify('Repository/UserRepository.php', 'new DateTimeImmutable($x)'),
        );
    }
}
