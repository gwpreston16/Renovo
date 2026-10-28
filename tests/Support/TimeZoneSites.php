<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

/**
 * Every place in `src/` that builds a date from something other than "now", or
 * reads PHP's default time zone, and what kind of date it reads (Phase 63).
 *
 * PHP parses a date string in `date.timezone`. The application's clock is UTC,
 * so on an install where the default zone is not UTC (K68) a date parsed here
 * and a date from the clock are not on the same footing. How wrong that is
 * depends on what the string is, which is what the classes record:
 *
 * - {@see self::DATE_ONLY}: a `Y-m-d` read from a column or a form, which
 *   becomes midnight in the default zone and is compared with the clock;
 * - {@see self::TIMESTAMP}: a `Y-m-d H:i:s` read from a column the application
 *   wrote in UTC, and used internally;
 * - {@see self::USER_FACING}: the same, where a person is shown the time;
 * - {@see self::ONE_ZONE}: parsed and formatted, or compared, inside one zone,
 *   so the zone cancels out.
 *
 * The scan is textual, and the classes come from the code on the line and from
 * the file, so a new site is found without anyone remembering to add it;
 * `tests/fixtures/time-zone-sites.tsv` is the list as it stands, and a test
 * fails when the source and the list disagree, which is when a person has to
 * look at the new site and say which class it is.
 */
final class TimeZoneSites
{
    public const DATE_ONLY = 'date-only';
    public const TIMESTAMP = 'timestamp';
    public const USER_FACING = 'user-facing';
    public const ONE_ZONE = 'one-zone';

    public const CLASSES = [self::DATE_ONLY, self::TIMESTAMP, self::USER_FACING, self::ONE_ZONE];

    /**
     * Files whose dates are all read and written inside one zone, or built from
     * constants, with the reason.
     */
    private const ONE_ZONE_FILES = [
        'Support/FrozenClock.php' => 'a test clock, read by the code under test',
        'Domain/CalendarGrid.php' => 'a month grid built from a year and a month, formatted back',
        'Domain/BillingCycle.php' => 'the length of a month, from a year and a month',
        'Persistence/PdoSessionHandler.php' => 'written and compared with date(): the default zone both ways',
        'Repository/AuthAttemptRepository.php' => 'parsed in the clock\'s zone, the one it was written in',
        'Service/Update/UpdateCheckService.php' => 'converted to UTC straight after parsing',
        'Application/Twig/AppExtension.php' => 'a value formatted in the zone it carries',
    ];

    /**
     * Files whose timestamps are shown to a person.
     */
    private const USER_FACING_FILES = [
        'Repository/SessionRepository.php',
        'Repository/AuditLogRepository.php',
        'Repository/ApiTokenRepository.php',
        'Repository/WebAuthnCredentialRepository.php',
        'Repository/NotificationChannelRepository.php',
        'Repository/BackupRunRepository.php',
        'Repository/BackupCopyRepository.php',
        'Repository/BackupRehearsalRepository.php',
        'Repository/BackupDestinationRepository.php',
    ];

    /**
     * Sites the line alone cannot place, by file and a fragment of the line:
     * the helper that parses a date column through a variable, and the import
     * parser, which reads the dates a person typed.
     */
    private const DATE_ONLY_SITES = [
        'Repository/ChargeRepository.php' => ['$written', 'first_any', 'is_string($value)'],
        'Repository/SubscriptionRepository.php' => ['is_string($value)'],
        'Service/Import/RowTranslator.php' => ['createFromFormat($format', 'date_create_immutable'],
    ];

    /**
     * What a line has to mention to be a date-only read: the columns that hold
     * a date without a time, a `Y-m-d` format and the form fields that carry
     * one.
     */
    private const DATE_ONLY_PATTERN = '/effective_from|due_date|period_date|start_date|end_date'
        . '|next_payment_date|promo_ends_on|trial_end|ends_on|!Y-m-d|\$last\b|\$key\b|\$date\)/';

    /**
     * The shapes that make a site: a date from a string, or the default zone.
     */
    private const SITE_PATTERN = '/new \\\\?DateTime(?:Immutable)?\((?!\s*(?:\'now\'|"now"|\)))'
        . '|DateTime(?:Immutable)?::createFromFormat\('
        . '|\bdate_create(?:_immutable)?\('
        . '|\bstrtotime\('
        . '|\bmktime\('
        . '|(?<![\w>$:])date\(/';

    /**
     * @return list<array{file: string, line: int, class: string, code: string}>
     */
    public static function scan(string $root): array
    {
        $src = rtrim($root, '/') . '/src';
        if (!is_dir($src)) {
            throw new RuntimeException('No src/ under ' . $root);
        }

        $files = [];
        $tree = new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($tree) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $sites = [];
        foreach ($files as $path) {
            $relative = substr($path, strlen($src) + 1);
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                $code = trim($line);
                if ($code === '' || preg_match('~^(\*|/\*|//|#)~', $code) === 1) {
                    continue;
                }
                if (preg_match('/\bfunction\s+date\(/', $code) === 1 || preg_match(self::SITE_PATTERN, $code) !== 1) {
                    continue;
                }
                $sites[] = [
                    'file' => $relative,
                    'line' => $index + 1,
                    'class' => self::classify($relative, $code),
                    'code' => $code,
                ];
            }
        }

        return $sites;
    }

    public static function classify(string $file, string $code): string
    {
        if (isset(self::ONE_ZONE_FILES[$file])) {
            return self::ONE_ZONE;
        }
        // A month label is built from "2026-12" and formatted again; nothing
        // is compared with the clock.
        if (str_contains($code, "-01") || str_contains($code, "'0001-01-01'")) {
            return self::ONE_ZONE;
        }
        if (preg_match(self::DATE_ONLY_PATTERN, $code) === 1) {
            return self::DATE_ONLY;
        }
        foreach (self::DATE_ONLY_SITES[$file] ?? [] as $fragment) {
            if (str_contains($code, $fragment)) {
                return self::DATE_ONLY;
            }
        }
        if (in_array($file, self::USER_FACING_FILES, true)) {
            return self::USER_FACING;
        }

        return self::TIMESTAMP;
    }

    /**
     * The reason a file is classed as one zone, for the findings.
     *
     * @return array<string, string>
     */
    public static function oneZoneReasons(): array
    {
        return self::ONE_ZONE_FILES;
    }

    /**
     * The list as the tab-separated lines kept in the fixture: file, class and
     * code, with no line numbers so a change elsewhere in the file does not
     * touch it, sorted so a diff shows only what changed.
     *
     * @param list<array{file: string, line: int, class: string, code: string}> $sites
     * @return list<string>
     */
    public static function lines(array $sites): array
    {
        $lines = array_map(
            static fn (array $site): string => $site['file'] . "\t" . $site['class'] . "\t" . $site['code'],
            $sites,
        );
        sort($lines);

        return $lines;
    }

    /**
     * @param list<array{file: string, line: int, class: string, code: string}> $sites
     * @return array<string, int>
     */
    public static function countsByClass(array $sites): array
    {
        $counts = array_fill_keys(self::CLASSES, 0);
        foreach ($sites as $site) {
            $counts[$site['class']]++;
        }

        return $counts;
    }
}
