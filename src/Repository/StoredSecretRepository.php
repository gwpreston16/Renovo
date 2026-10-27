<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\StoredSecretKind;
use App\Persistence\Database;
use App\Service\InstanceSettingsService;

/**
 * Every encrypted value the application stores, read and rewritten by kind,
 * for `secrets:rotate`.
 *
 * Unscoped on purpose: rotation is an operator's instance-wide job run from
 * the console, and it never reads a value as plaintext outside the cipher.
 * The row id is the user id for a two-factor seed, the channel id for a
 * channel, and 0 for the one rate provider key.
 */
class StoredSecretRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The next batch of stored values after `$afterId`, in id order. An empty
     * value means nothing is stored; it is returned so a short batch still
     * means the end.
     *
     * @return list<array{id: int, value: string}>
     */
    public function batch(StoredSecretKind $kind, int $afterId, int $limit): array
    {
        [$table, $idColumn, $valueColumn, $where] = $this->shape($kind);

        $rows = $this->db->fetchAll(
            'SELECT ' . $idColumn . ' AS id, ' . $valueColumn . ' AS value FROM ' . $table
            . ' WHERE ' . $idColumn . ' > :after' . $where
            . ' ORDER BY id LIMIT ' . max(1, $limit),
            ['after' => $afterId, ...$this->whereParams($kind)],
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'value' => (string) $row['value']],
            $rows,
        );
    }

    /**
     * Replace one value, only if it still holds what was read — a value the
     * application rewrote in between is left for the next run.
     */
    public function replace(StoredSecretKind $kind, int $id, string $was, string $value): bool
    {
        [$table, $idColumn, $valueColumn, $where] = $this->shape($kind);

        return $this->db->execute(
            'UPDATE ' . $table . ' SET ' . $valueColumn . ' = :value'
            . ' WHERE ' . $idColumn . ' = :id AND ' . $valueColumn . ' = :was' . $where,
            ['value' => $value, 'id' => $id, 'was' => $was, ...$this->whereParams($kind)],
        ) > 0;
    }

    /**
     * @return array{string, string, string, string}
     */
    private function shape(StoredSecretKind $kind): array
    {
        return match ($kind) {
            StoredSecretKind::TotpSecret => ['user_totp', 'user_id', 'secret', ''],
            StoredSecretKind::ChannelConfig => ['notification_channels', 'id', 'config', ''],
            // A destination with no credentials (a directory) stores NULL.
            StoredSecretKind::BackupDestinationSecret => [
                'backup_destinations',
                'id',
                'secret',
                ' AND secret IS NOT NULL',
            ],
            // One row: its "id" is a constant 0, so the batch reads it once.
            StoredSecretKind::RateProviderKey => [
                'instance_settings',
                '0',
                'setting_value',
                ' AND setting_key = :setting_key',
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    private function whereParams(StoredSecretKind $kind): array
    {
        return $kind === StoredSecretKind::RateProviderKey
            ? ['setting_key' => InstanceSettingsService::KEY_RATE_PROVIDER_KEY]
            : [];
    }
}
