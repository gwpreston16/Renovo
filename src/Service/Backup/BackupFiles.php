<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The stored files an instance backup carries, and where each one lives
 * (Phase 38).
 *
 * The list comes from the rows, never from a directory listing: an attachment
 * is in the backup because an `attachments` row names it, which is also how a
 * missing file is noticed. Every stored value is checked against the shape
 * its storage class writes before it is turned into a path — K1 means a
 * subscription's `logo_path` can hold anything a member typed, and a naive
 * concatenation would put any file on the server into the archive.
 *
 * The same shapes are the only entry names a restore will write.
 */
final class BackupFiles
{
    /**
     * For each kind: the table and column that name the files, the pattern a
     * stored value must match (capturing the path relative to the kind's
     * directory), and the entry pattern inside the archive.
     */
    private const KINDS = [
        'attachments' => [
            'table' => 'attachments',
            'column' => 'stored_path',
            'stored' => '#^([0-9]{1,20}/[0-9a-f]{32}\.(?:pdf|png|jpg|webp|gif))$#D',
        ],
        'avatars' => [
            'table' => 'users',
            'column' => 'avatar_path',
            'stored' => '#^([0-9]{1,20}/[0-9a-f]{32}\.png)$#D',
        ],
        'logos' => [
            'table' => ['subscriptions', 'payment_methods'],
            'column' => 'logo_path',
            'stored' => '#^assets/logos/([0-9a-f]{32}\.(?:png|jpg|gif|webp))$#D',
        ],
    ];

    /** Every entry name a file may have inside an instance backup. */
    public const ENTRY_PATTERN = '#^files/(?:attachments/[0-9]{1,20}/[0-9a-f]{32}\.(?:pdf|png|jpg|webp|gif)'
        . '|avatars/[0-9]{1,20}/[0-9a-f]{32}\.png'
        . '|logos/[0-9a-f]{32}\.(?:png|jpg|gif|webp))$#D';

    /**
     * @param array<string, string> $directories Kind => absolute directory.
     */
    public function __construct(private readonly array $directories)
    {
    }

    /**
     * Which kind of file a row of this table may name, if any.
     *
     * @return list<array{kind: string, column: string}>
     */
    public function referencesIn(string $table): array
    {
        $found = [];
        foreach (self::KINDS as $kind => $spec) {
            if (in_array($table, (array) $spec['table'], true)) {
                $found[] = ['kind' => $kind, 'column' => $spec['column']];
            }
        }

        return $found;
    }

    /**
     * The archive entry for a stored value, or null when the value is not one
     * the storage class would have written.
     */
    public function entryFor(string $kind, string $storedValue): ?string
    {
        if (preg_match(self::KINDS[$kind]['stored'], $storedValue, $match) !== 1) {
            return null;
        }

        return 'files/' . $kind . '/' . $match[1];
    }

    /**
     * Where an archive entry's file lives on this server. Null for any name
     * that is not one an instance backup writes.
     */
    public function pathFor(string $entry): ?string
    {
        if (preg_match(self::ENTRY_PATTERN, $entry) !== 1) {
            return null;
        }

        [, $kind, $relative] = explode('/', $entry, 3);

        return rtrim($this->directories[$kind], '/') . '/' . $relative;
    }

    /**
     * @return array<string, string> Kind => absolute directory.
     */
    public function directories(): array
    {
        return $this->directories;
    }
}
