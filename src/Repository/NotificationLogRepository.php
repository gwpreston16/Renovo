<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AlertType;
use App\Persistence\Database;
use App\Support\Clock;
use DateTimeImmutable;
use PDOException;

/**
 * The record of what has been sent, and the thing that stops it being sent
 * twice.
 *
 * The important method is `claim()`, and the important detail is that it claims
 * by *inserting*. Checking whether a notification has been sent and then
 * sending it is two steps with a gap in between, and two scheduler runs — a
 * cron that overlaps its predecessor, an operator running the command by hand
 * while the container is doing the same — will both find nothing and both send.
 * Inserting first turns the question over to the unique index, where the answer
 * is decided by the database rather than by timing.
 *
 * A claim that collides is not necessarily a refusal. The existing row is read
 * and its status decides:
 *
 *  - `sent` — nothing to do, and nothing will ever be done again for this
 *    occurrence. This is the idempotency guarantee.
 *  - `failed` — the channel was down. Retried, up to a limit, because a webhook
 *    that was unreachable for an hour should not cost the user the alert.
 *  - `pending` — another run claimed it. While the claim is younger than the
 *    lease it is that run's, and this one is refused; past the lease the run
 *    is presumed to have crashed mid-send, and the row is taken over: a
 *    duplicate is a nuisance, a silently dropped cancellation deadline is not.
 *
 * Taking a row over is one conditional UPDATE whose affected-row count decides
 * who won, so two runs reading the same `failed` or stale `pending` row cannot
 * both claim it.
 */
final class NotificationLogRepository extends AbstractRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * How long a `pending` claim is believed to belong to a live run. Longer
     * than any single send (the HTTP client's timeouts are seconds), short
     * enough that a crashed run's rows go out on the next daily run.
     */
    public const CLAIM_LEASE_SECONDS = 900;

    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'notification_log';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'user_id', 'channel_id', 'alert_type', 'subject_type', 'subject_id', 'status', 'created_at'];
    }

    /**
     * When this user's last digest was delivered, on any channel.
     *
     * A digest is one ledger row per period, not one per item in it, so the
     * ledger cannot stop an event-shaped item — a price change — appearing in
     * two consecutive digests. Bounding each digest to what happened since the
     * previous one does.
     *
     * Delivered, not merely claimed: a digest that failed on every channel
     * told nobody anything, and counting it would move the window past the
     * changes it was carrying — lost for good, since a failed digest is not
     * retried once its period has passed.
     */
    public function lastDigestAt(int $userId): ?DateTimeImmutable
    {
        $value = $this->db->fetchValue(
            'SELECT MAX(' . $this->quote('created_at') . ') FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('subject_type') . ' = :digest'
            . ' AND ' . $this->quote('status') . ' = :sent',
            ['user' => $userId, 'digest' => \App\Notification\Alert::SUBJECT_DIGEST, 'sent' => self::STATUS_SENT],
        );

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /**
     * Claim the right to send one notification.
     *
     * Must not be called inside a transaction: the colliding insert is expected,
     * and on PostgreSQL a failed statement aborts the transaction it is in.
     *
     * @return int|null The log row to report against, or null when this
     *         notification must not be sent.
     */
    public function claim(
        int $userId,
        int $channelId,
        AlertType $type,
        string $subjectType,
        int $subjectId,
        string $occurrenceKey,
        int $maxAttempts = 3,
    ): ?int {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        try {
            return $this->db->insert($this->table(), [
                'user_id' => $userId,
                'channel_id' => $channelId,
                'alert_type' => $type->value,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'occurrence_key' => mb_substr($occurrenceKey, 0, 64),
                'status' => self::STATUS_PENDING,
                'attempts' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if (!Database::isUniqueViolation($exception)) {
                throw $exception;
            }
        }

        $row = $this->db->fetchOne(
            'SELECT ' . $this->quote('id') . ', ' . $this->quote('status') . ', ' . $this->quote('attempts')
            . ' FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('channel_id') . ' = :channel'
            . ' AND ' . $this->quote('alert_type') . ' = :type'
            . ' AND ' . $this->quote('subject_type') . ' = :subject_type'
            . ' AND ' . $this->quote('subject_id') . ' = :subject_id'
            . ' AND ' . $this->quote('occurrence_key') . ' = :occurrence',
            [
                'user' => $userId,
                'channel' => $channelId,
                'type' => $type->value,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'occurrence' => mb_substr($occurrenceKey, 0, 64),
            ],
        );

        if ($row === null) {
            // The colliding row has been deleted between the insert and the
            // read. Vanishingly unlikely, and silence is the safe answer.
            return null;
        }

        $status = (string) $row['status'];
        $attempts = (int) $row['attempts'];

        if ($status === self::STATUS_SENT || $attempts >= $maxAttempts) {
            return null;
        }

        $id = (int) $row['id'];
        $stale = $this->clock->now()->modify('-' . self::CLAIM_LEASE_SECONDS . ' seconds')->format('Y-m-d H:i:s');

        $taken = $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('attempts') . ' = ' . $this->quote('attempts') . ' + 1,'
            . ' ' . $this->quote('status') . ' = :status,'
            . ' ' . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('id') . ' = :id'
            . ' AND ' . $this->quote('attempts') . ' < :max'
            . ' AND (' . $this->quote('status') . ' = :failed'
            . ' OR (' . $this->quote('status') . ' = :pending AND ' . $this->quote('updated_at') . ' <= :stale))',
            [
                'status' => self::STATUS_PENDING,
                'now' => $now,
                'id' => $id,
                'max' => $maxAttempts,
                'failed' => self::STATUS_FAILED,
                'pending' => self::STATUS_PENDING,
                'stale' => $stale,
            ],
        );

        // Zero rows: another run holds a live claim, or already took this one.
        return $taken === 1 ? $id : null;
    }

    /**
     * Whether an occurrence has a ledger row on any channel that is not
     * `sent`: an attempt was made and did not land, so it is worth raising
     * again. No row at all means nothing was attempted (the member had the
     * alert switched off, say), which is not a failure to retry.
     */
    public function hasUndelivered(int $userId, string $subjectType, int $subjectId, string $occurrenceKey): bool
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('subject_type') . ' = :subject_type'
            . ' AND ' . $this->quote('subject_id') . ' = :subject_id'
            . ' AND ' . $this->quote('occurrence_key') . ' = :occurrence'
            . ' AND ' . $this->quote('status') . ' <> :sent',
            [
                'user' => $userId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'occurrence' => mb_substr($occurrenceKey, 0, 64),
                'sent' => self::STATUS_SENT,
            ],
        ) > 0;
    }

    public function markSent(int $id): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('status') . ' = :status,'
            . ' ' . $this->quote('last_error') . ' = NULL,'
            . ' ' . $this->quote('sent_at') . ' = :now,'
            . ' ' . $this->quote('updated_at') . ' = :updated'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['status' => self::STATUS_SENT, 'now' => $now, 'updated' => $now, 'id' => $id],
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('status') . ' = :status,'
            . ' ' . $this->quote('last_error') . ' = :error,'
            . ' ' . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            [
                'status' => self::STATUS_FAILED,
                'error' => mb_substr($error, 0, 500),
                'now' => $now,
                'id' => $id,
            ],
        );
    }

    /**
     * How many notifications a user has been sent since a moment, for the
     * outbound rate limit.
     */
    /**
     * Instance-wide delivery counts for the metrics endpoint. Integers only —
     * no user, no channel, no subject.
     *
     * @return array{sent: int, failed: int, pending: int}
     */
    public function instanceTotals(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('status') . ' AS status, COUNT(*) AS total'
            . ' FROM ' . $this->quote('notification_log')
            . ' GROUP BY ' . $this->quote('status'),
        );

        $totals = ['sent' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $totals)) {
                $totals[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return $totals;
    }

    public function countSince(int $userId, DateTimeImmutable $since, ?int $subjectId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('created_at') . ' >= :since';

        $params = ['user' => $userId, 'since' => $since->format('Y-m-d H:i:s')];

        if ($subjectId !== null) {
            $sql .= ' AND ' . $this->quote('subject_id') . ' = :subject';
            $params['subject'] = $subjectId;
        }

        return (int) $this->db->fetchValue($sql, $params);
    }

    /**
     * The most recent entries for a user, newest first, for the settings page.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentForUser(int $userId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT ' . $this->quote($this->table()) . '.*,'
            . ' ch.' . $this->quote('label') . ' AS channel_label'
            . ' FROM ' . $this->quote($this->table())
            . ' LEFT JOIN ' . $this->quote('notification_channels') . ' ch'
            . ' ON ch.' . $this->quote('id') . ' = ' . $this->qualify('channel_id')
            . ' WHERE ' . $this->qualify('user_id') . ' = :user'
            . ' ORDER BY ' . $this->qualify('created_at') . ' DESC, ' . $this->qualify('id') . ' DESC'
            . ' LIMIT :limit',
            ['user' => $userId, 'limit' => max(1, $limit)],
        );
    }

    /**
     * Housekeeping: the ledger only has to remember long enough to stop a
     * re-send, and an occurrence key that has passed can never recur.
     */
    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('created_at') . ' < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
