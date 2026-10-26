<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\Database;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The ledger behind login and password-reset throttling.
 *
 * Attempts are recorded against both a hashed account key and the client IP so
 * the two limits can be applied independently: a password-spraying attacker
 * hitting many accounts from one address is throttled by the IP limit, and a
 * distributed attack on one account is throttled by the account limit.
 *
 * The account key is stored as a hash so that the table does not become a list
 * of which email addresses exist on the instance.
 */
final class AuthAttemptRepository extends AbstractRepository
{
    public const KIND_LOGIN = 'login';
    public const KIND_RESET = 'reset';
    /** A request to send the email-confirmation link again. */
    public const KIND_VERIFY_RESEND = 'verify_resend';
    // The second-factor step has its own budget. Sharing the login one would
    // mean a user who mistyped their password twice had fewer tries left for a
    // six-digit code than the code's own guessing odds assume.
    public const KIND_TWO_FACTOR = '2fa';

    /** More than any limit needs to see; keeps a flood from an address cheap to read. */
    public const FAILURE_HISTORY_CAP = 500;

    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'auth_attempts';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'kind', 'account_key', 'ip_address', 'attempted_at', 'successful'];
    }

    public function record(string $kind, string $accountKey, string $ipAddress, bool $successful): void
    {
        $this->db->insert('auth_attempts', [
            'kind' => $kind,
            'account_key' => $this->hashAccountKey($accountKey),
            'ip_address' => substr($ipAddress, 0, 45),
            'successful' => $successful,
            'attempted_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function countFailuresForAccount(string $kind, string $accountKey, DateTimeImmutable $since): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('auth_attempts')
            . ' WHERE ' . $this->quote('kind') . ' = :kind'
            . ' AND ' . $this->quote('account_key') . ' = :account'
            . ' AND ' . $this->quote('successful') . ' = :failed'
            . ' AND ' . $this->quote('attempted_at') . ' >= :since',
            [
                'kind' => $kind,
                'account' => $this->hashAccountKey($accountKey),
                'failed' => false,
                'since' => $since->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function countFailuresForIp(string $kind, string $ipAddress, DateTimeImmutable $since): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('auth_attempts')
            . ' WHERE ' . $this->quote('kind') . ' = :kind'
            . ' AND ' . $this->quote('ip_address') . ' = :ip'
            . ' AND ' . $this->quote('successful') . ' = :failed'
            . ' AND ' . $this->quote('attempted_at') . ' >= :since',
            [
                'kind' => $kind,
                'ip' => substr($ipAddress, 0, 45),
                'failed' => false,
                'since' => $since->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * When each failure for an account happened since a moment, oldest first.
     * At most the most recent {@see self::FAILURE_HISTORY_CAP}.
     *
     * @return list<DateTimeImmutable>
     */
    public function failureTimesForAccount(string $kind, string $accountKey, DateTimeImmutable $since): array
    {
        return $this->failureTimes('account_key', $this->hashAccountKey($accountKey), $kind, $since);
    }

    /**
     * When each failure from an address happened since a moment, oldest first.
     * At most the most recent {@see self::FAILURE_HISTORY_CAP}.
     *
     * @return list<DateTimeImmutable>
     */
    public function failureTimesForIp(string $kind, string $ipAddress, DateTimeImmutable $since): array
    {
        return $this->failureTimes('ip_address', substr($ipAddress, 0, 45), $kind, $since);
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function failureTimes(string $column, string $value, string $kind, DateTimeImmutable $since): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('attempted_at') . ' FROM ' . $this->quote('auth_attempts')
            . ' WHERE ' . $this->quote('kind') . ' = :kind'
            . ' AND ' . $this->quote($column) . ' = :value'
            . ' AND ' . $this->quote('successful') . ' = :failed'
            . ' AND ' . $this->quote('attempted_at') . ' >= :since'
            . ' ORDER BY ' . $this->quote('attempted_at') . ' DESC, ' . $this->quote('id') . ' DESC'
            . ' LIMIT ' . self::FAILURE_HISTORY_CAP,
            ['kind' => $kind, 'value' => $value, 'failed' => false, 'since' => $since->format('Y-m-d H:i:s')],
        );

        $timezone = $this->clock->now()->getTimezone();
        $times = [];
        foreach (array_reverse($rows) as $row) {
            $times[] = new DateTimeImmutable((string) $row['attempted_at'], $timezone);
        }

        return $times;
    }

    /**
     * Clear an account's failures after a successful authentication, so a user
     * who eventually remembers their password is not left locked out.
     */
    public function clearForAccount(string $kind, string $accountKey): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->quote('auth_attempts')
            . ' WHERE ' . $this->quote('kind') . ' = :kind'
            . ' AND ' . $this->quote('account_key') . ' = :account',
            ['kind' => $kind, 'account' => $this->hashAccountKey($accountKey)],
        );
    }

    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote('auth_attempts')
            . ' WHERE ' . $this->quote('attempted_at') . ' < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }

    private function hashAccountKey(string $accountKey): string
    {
        return hash('sha256', mb_strtolower(trim($accountKey)));
    }
}
