<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\Database;
use DateTimeImmutable;
use PDOException;

/**
 * The counters behind the API's rate limits (Phase 42): one row per key per
 * fixed window.
 *
 * Unscoped, like the sign-in throttle's ledger: the keys are a token id or a
 * client address, never household data, and the limit has to apply before
 * anybody is known to belong anywhere.
 *
 * Counting is an update first and an insert only when the window has no row
 * yet, rather than either engine's upsert dialect. Two workers opening the
 * same window at the same instant collide on the primary key; the loser of
 * the insert updates instead, so no hit is lost.
 */
final class ApiRateWindowRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'api_rate_windows';
    }

    protected function filterableColumns(): array
    {
        return ['key', 'window_start'];
    }

    /**
     * Count one more request against a key's window.
     *
     * @return int The window's count, this request included.
     */
    public function hit(string $key, DateTimeImmutable $windowStart): int
    {
        $params = ['key' => $key, 'start' => $windowStart->format('Y-m-d H:i:s')];

        if ($this->increment($params) === 0) {
            try {
                $this->db->execute(
                    'INSERT INTO ' . $this->quote('api_rate_windows')
                    . ' (' . $this->quote('key') . ', ' . $this->quote('window_start')
                    . ', ' . $this->quote('hits') . ')'
                    . ' VALUES (:key, :start, 1)',
                    $params,
                );
            } catch (PDOException $exception) {
                if (!Database::isUniqueViolation($exception)) {
                    throw $exception;
                }

                $this->increment($params);
            }
        }

        return $this->hits($key, $windowStart);
    }

    /**
     * A key's count in a window, without adding to it.
     */
    public function hits(string $key, DateTimeImmutable $windowStart): int
    {
        return (int) $this->db->fetchValue(
            'SELECT ' . $this->quote('hits') . ' FROM ' . $this->quote('api_rate_windows')
            . ' WHERE ' . $this->quote('key') . ' = :key AND ' . $this->quote('window_start') . ' = :start',
            ['key' => $key, 'start' => $windowStart->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Remove windows that started before the cutoff — `maintenance:prune`
     * passes an hour ago, well past the longest window.
     *
     * @return int How many were removed.
     */
    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote('api_rate_windows')
            . ' WHERE ' . $this->quote('window_start') . ' < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @param array{key: string, start: string} $params
     */
    private function increment(array $params): int
    {
        return $this->db->execute(
            'UPDATE ' . $this->quote('api_rate_windows')
            . ' SET ' . $this->quote('hits') . ' = ' . $this->quote('hits') . ' + 1'
            . ' WHERE ' . $this->quote('key') . ' = :key AND ' . $this->quote('window_start') . ' = :start',
            $params,
        );
    }
}
