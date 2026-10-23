<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BackupDestinationType;
use App\Domain\Entity\BackupDestinationRecord;
use App\Persistence\Database;
use App\Security\KeyRing;
use App\Security\SecretCipher;
use App\Security\UnreadableSecretException;
use App\Service\Backup\BackupRetention;
use App\Support\Clock;
use DateTimeImmutable;
use JsonException;
use SensitiveParameter;

/**
 * Backup destinations (Phase 39).
 *
 * The credentials are one JSON document encrypted with `SecretCipher` under
 * purpose `backup-destination`; the rest of the settings are plain JSON,
 * since they name where backups go and none of it opens anything.
 *
 * Instance-wide and unscoped: only an instance administrator's pages and the
 * operator's console reach it.
 */
final class BackupDestinationRepository extends AbstractRepository
{
    private readonly SecretCipher $cipher;

    public function __construct(Database $db, private readonly Clock $clock, KeyRing $keys)
    {
        parent::__construct($db);
        $this->cipher = new SecretCipher($keys, SecretCipher::PURPOSE_BACKUP_DESTINATION);
    }

    protected function table(): string
    {
        return 'backup_destinations';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'type', 'enabled'];
    }

    /**
     * @return list<BackupDestinationRecord> In the order they were added.
     */
    public function all(bool $enabledOnly = false): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote($this->table())
            . ($enabledOnly ? ' WHERE ' . $this->quote('enabled') . ' = :on' : '')
            . ' ORDER BY ' . $this->quote('id'),
            $enabledOnly ? ['on' => true] : [],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(int $id): ?BackupDestinationRecord
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote($this->table()) . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, string|bool> $config
     * @param array<string, string> $secrets
     */
    public function create(
        BackupDestinationType $type,
        string $name,
        array $config,
        #[SensitiveParameter] array $secrets,
        bool $enabled,
        BackupRetention $retention,
    ): int {
        $now = $this->now();

        return $this->db->insert($this->table(), [
            'type' => $type->value,
            'name' => $name,
            'config' => $this->json($config),
            'secret' => $this->encode($secrets),
            'enabled' => $enabled,
            'keep_daily' => $retention->daily,
            'keep_weekly' => $retention->weekly,
            'keep_monthly' => $retention->monthly,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, string|bool> $config
     * @param array<string, string>|null $secrets Null keeps what is stored.
     */
    public function update(
        int $id,
        string $name,
        array $config,
        #[SensitiveParameter] ?array $secrets,
        BackupRetention $retention,
    ): void {
        $set = [
            'name' => $name,
            'config' => $this->json($config),
            'keep_daily' => $retention->daily,
            'keep_weekly' => $retention->weekly,
            'keep_monthly' => $retention->monthly,
            'updated_at' => $this->now(),
        ];
        if ($secrets !== null) {
            $set['secret'] = $this->encode($secrets);
        }

        $params = ['id' => $id];
        $assignments = [];
        foreach ($set as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $params[$column] = $value;
        }

        $this->db->execute(
            'UPDATE ' . $this->quote($this->table()) . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $this->quote('id') . ' = :id',
            $params,
        );
    }

    /**
     * Only the flag: no credential is round-tripped through the switch.
     */
    public function setEnabled(int $id, bool $enabled): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('enabled') . ' = :on, ' . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['on' => $enabled, 'now' => $this->now(), 'id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->quote($this->table()) . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $id],
        );
    }

    /**
     * When the first enabled destination was added: until two periods after
     * it, nothing can be stale.
     */
    public function firstEnabledAt(): ?DateTimeImmutable
    {
        $value = $this->db->fetchValue(
            'SELECT MIN(' . $this->quote('created_at') . ') FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('enabled') . ' = :on',
            ['on' => true],
        );

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    public function count(bool $enabledOnly = false): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote($this->table())
            . ($enabledOnly ? ' WHERE ' . $this->quote('enabled') . ' = :on' : ''),
            $enabledOnly ? ['on' => true] : [],
        );
    }

    /**
     * @param array<string, string> $secrets
     */
    private function encode(#[SensitiveParameter] array $secrets): ?string
    {
        $secrets = array_filter($secrets, static fn (string $value): bool => $value !== '');

        return $secrets === [] ? null : $this->cipher->encrypt($this->json($secrets));
    }

    /**
     * @param array<string, string|bool> $values
     */
    private function json(array $values): string
    {
        try {
            return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return '{}';
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): BackupDestinationRecord
    {
        $config = [];
        foreach ($this->decode((string) $row['config']) as $key => $value) {
            if (is_string($value) || is_bool($value)) {
                $config[$key] = $value;
            }
        }

        $secrets = [];
        $unreadable = false;
        if (is_string($row['secret']) && $row['secret'] !== '') {
            try {
                foreach ($this->decode($this->cipher->decrypt($row['secret'])) as $key => $value) {
                    if (is_string($value)) {
                        $secrets[$key] = $value;
                    }
                }
            } catch (UnreadableSecretException) {
                $unreadable = true;
            }
        }

        return new BackupDestinationRecord(
            id: (int) $row['id'],
            type: BackupDestinationType::tryFrom((string) $row['type']) ?? BackupDestinationType::Directory,
            name: (string) $row['name'],
            config: $config,
            secrets: $secrets,
            secretsUnreadable: $unreadable,
            enabled: $this->db->platform()->toBoolean($row['enabled']),
            retention: new BackupRetention(
                (int) $row['keep_daily'],
                (int) $row['keep_weekly'],
                (int) $row['keep_monthly'],
            ),
            createdAt: is_string($row['created_at']) && $row['created_at'] !== ''
                ? new DateTimeImmutable($row['created_at'])
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
