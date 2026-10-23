<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\NotificationChannel;
use App\Persistence\Database;
use App\Security\KeyRing;
use App\Security\SecretCipher;
use App\Security\UnreadableSecretException;
use App\Support\Clock;
use DateTimeImmutable;
use JsonException;

/**
 * Channels, keyed by the user who owns them.
 *
 * Unscoped in the household sense, like sessions and auth tokens: a
 * notification channel is a property of an account, not of a household, and
 * there is no isolation mode under which one member may read another's Gotify
 * token. Every method therefore takes a user id and every statement filters on
 * it — the same structural guarantee as the scoped repositories, against a
 * different axis.
 */
final class NotificationChannelRepository extends AbstractRepository
{
    private readonly SecretCipher $cipher;

    public function __construct(Database $db, private readonly Clock $clock, KeyRing $keys)
    {
        parent::__construct($db);
        $this->cipher = new SecretCipher($keys, SecretCipher::PURPOSE_CHANNEL_CONFIG);
    }

    protected function table(): string
    {
        return 'notification_channels';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'user_id', 'channel_type', 'is_active'];
    }

    /**
     * @return list<NotificationChannel>
     */
    public function findAllForUser(int $userId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('user_id') . ' = :user';

        if ($activeOnly) {
            $sql .= ' AND ' . $this->quote('is_active') . ' = :active';
        }

        $sql .= ' ORDER BY ' . $this->quote('label') . ' ASC, ' . $this->quote('id') . ' ASC';

        $params = ['user' => $userId];
        if ($activeOnly) {
            $params['active'] = $this->db->platform()->booleanParameter(true);
        }

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    public function find(int $userId, int $id): ?NotificationChannel
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('id') . ' = :id'
            . ' AND ' . $this->quote('user_id') . ' = :user',
            ['id' => $id, 'user' => $userId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, string> $config
     */
    public function create(int $userId, string $type, string $label, array $config, bool $isActive = true): int
    {
        $now = $this->now();

        return $this->db->insert($this->table(), [
            'user_id' => $userId,
            'channel_type' => $type,
            'label' => $label,
            'config' => $this->encode($config),
            'is_active' => $this->db->platform()->booleanParameter($isActive),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Turn a channel on or off and touch nothing else — in particular not its
     * stored config, which may be ciphertext this instance cannot open.
     */
    public function setActive(int $userId, int $id, bool $isActive): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('is_active') . ' = :active,'
            . ' ' . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('id') . ' = :id'
            . ' AND ' . $this->quote('user_id') . ' = :user',
            [
                'active' => $this->db->platform()->booleanParameter($isActive),
                'now' => $this->now(),
                'id' => $id,
                'user' => $userId,
            ],
        );
    }

    /**
     * @param array<string, string> $config
     */
    public function update(int $userId, int $id, string $label, array $config, bool $isActive): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('label') . ' = :label,'
            . ' ' . $this->quote('config') . ' = :config,'
            . ' ' . $this->quote('is_active') . ' = :active,'
            . ' ' . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('id') . ' = :id'
            . ' AND ' . $this->quote('user_id') . ' = :user',
            [
                'label' => $label,
                'config' => $this->encode($config),
                'active' => $this->db->platform()->booleanParameter($isActive),
                'now' => $this->now(),
                'id' => $id,
                'user' => $userId,
            ],
        );
    }

    public function delete(int $userId, int $id): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('id') . ' = :id'
            . ' AND ' . $this->quote('user_id') . ' = :user',
            ['id' => $id, 'user' => $userId],
        );
    }

    /**
     * Record the outcome of a delivery attempt against the channel.
     *
     * Shown on the settings page, which is the difference between "my
     * notifications stopped" and "my token expired on the 3rd".
     */
    public function recordResult(int $id, ?string $error): void
    {
        $now = $this->now();

        // Two statements rather than one with a CASE: the conditional form
        // would compare a bound parameter against a literal, and the two
        // engines disagree about how an untyped parameter is coerced in that
        // position. Portable SQL is the rule here, and this is what it costs.
        if ($error === null) {
            $this->db->execute(
                'UPDATE ' . $this->quote($this->table())
                . ' SET ' . $this->quote('last_error') . ' = NULL,'
                . ' ' . $this->quote('last_success_at') . ' = :now,'
                . ' ' . $this->quote('updated_at') . ' = :updated'
                . ' WHERE ' . $this->quote('id') . ' = :id',
                ['now' => $now, 'updated' => $now, 'id' => $id],
            );

            return;
        }

        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('last_error') . ' = :error,'
            . ' ' . $this->quote('updated_at') . ' = :updated'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['error' => mb_substr($error, 0, 500), 'updated' => $now, 'id' => $id],
        );
    }

    /**
     * The config as stored: the whole JSON document as one ciphertext, secret
     * fields and the rest alike. Nothing filters on a field, so nothing is lost
     * by encrypting the lot, and a dump shows no bot token or webhook URL.
     *
     * @param array<string, string> $config
     */
    private function encode(array $config): string
    {
        try {
            $json = json_encode($config, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $json = '{}';
        }

        return $this->cipher->encrypt($json);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NotificationChannel
    {
        $config = [];
        $unreadable = false;
        try {
            // An unprefixed row was written before configs were encrypted, and
            // is read as plain JSON until the backfill migration reaches it.
            $stored = (string) $row['config'];
            $json = SecretCipher::isEncrypted($stored) ? $this->cipher->decrypt($stored) : $stored;
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && is_scalar($value)) {
                        $config[$key] = (string) $value;
                    }
                }
            }
        } catch (UnreadableSecretException) {
            // Under a key that is no longer configured: read as empty, so the
            // channel reports itself misconfigured, and marked so that nothing
            // overwrites the ciphertext before the key is found.
            $config = [];
            $unreadable = true;
        } catch (JsonException) {
            // A config row that cannot be read — corrupt, or under a key that
            // is no longer configured — is treated as empty rather than fatal:
            // the channel then reports itself as misconfigured, which is
            // exactly what it is, instead of breaking the settings page.
            $config = [];
        }

        return new NotificationChannel(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['channel_type'],
            (string) $row['label'],
            $config,
            $this->db->platform()->toBoolean($row['is_active']),
            $row['last_error'] === null ? null : (string) $row['last_error'],
            $row['last_success_at'] === null ? null : new DateTimeImmutable((string) $row['last_success_at']),
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
            $unreadable,
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
