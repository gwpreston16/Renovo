<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A release number, compared as semantic versioning says rather than as text:
 * 1.10.0 is newer than 1.9.0, and 1.2.0-rc.1 comes before 1.2.0.
 *
 * A leading `v` is accepted and dropped, because that is how releases are
 * tagged (`v1.7.0`). Build metadata (`+…`) is kept out of the comparison, as
 * the specification requires. Anything else that is not `MAJOR.MINOR.PATCH`
 * with an optional pre-release does not parse.
 */
final class SemanticVersion
{
    private const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        . '(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

    /**
     * @param list<string> $preRelease
     */
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        private readonly array $preRelease,
        private readonly string $text,
    ) {
    }

    public static function parse(string $value): ?self
    {
        $value = trim($value);
        if (str_starts_with($value, 'v') || str_starts_with($value, 'V')) {
            $value = substr($value, 1);
        }

        if (strlen($value) > 64 || preg_match(self::PATTERN, $value, $match) !== 1) {
            return null;
        }

        // A number too large for an integer is no release anybody tagged.
        foreach ([$match[1], $match[2], $match[3]] as $part) {
            if (strlen($part) > 9) {
                return null;
            }
        }

        $preRelease = isset($match[4]) ? explode('.', $match[4]) : [];
        $text = $match[1] . '.' . $match[2] . '.' . $match[3] . ($preRelease === [] ? '' : '-' . $match[4]);

        return new self((int) $match[1], (int) $match[2], (int) $match[3], $preRelease, $text);
    }

    /**
     * Negative, zero or positive as this version is older than, the same as or
     * newer than the other.
     */
    public function compare(self $other): int
    {
        $core = [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
        if ($core !== 0) {
            return $core;
        }

        // A release outranks any pre-release of the same number.
        if ($this->preRelease === [] || $other->preRelease === []) {
            return ($this->preRelease === [] ? 1 : 0) - ($other->preRelease === [] ? 1 : 0);
        }

        $count = max(count($this->preRelease), count($other->preRelease));
        for ($i = 0; $i < $count; $i++) {
            $mine = $this->preRelease[$i] ?? null;
            $theirs = $other->preRelease[$i] ?? null;
            if ($mine === null || $theirs === null) {
                // The one with fewer identifiers comes first.
                return $mine === null ? -1 : 1;
            }

            $order = self::compareIdentifier($mine, $theirs);
            if ($order !== 0) {
                return $order;
            }
        }

        return 0;
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    /**
     * The version without its `v` or build metadata, e.g. "1.7.0".
     */
    public function toString(): string
    {
        return $this->text;
    }

    private static function compareIdentifier(string $a, string $b): int
    {
        $aNumeric = ctype_digit($a);
        $bNumeric = ctype_digit($b);

        if ($aNumeric && $bNumeric) {
            return (int) $a <=> (int) $b;
        }

        // Numeric identifiers sort before alphanumeric ones.
        if ($aNumeric !== $bNumeric) {
            return $aNumeric ? -1 : 1;
        }

        return strcmp($a, $b) <=> 0;
    }
}
