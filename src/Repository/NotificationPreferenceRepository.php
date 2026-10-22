<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\DigestMode;
use App\Domain\Entity\NotificationPreferences;
use App\Persistence\Database;
use App\Support\Clock;

/**
 * One row per user, or none.
 *
 * "None" is the common case and is not an error: a user who has never opened
 * the notification settings has no row, and reading their preferences returns
 * the defaults. Writing is an upsert expressed as update-then-insert, because
 * the two engines spell a real upsert differently and this table is written
 * once in a blue moon.
 */
final class NotificationPreferenceRepository extends AbstractRepository
{
    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'notification_preferences';
    }

    protected function filterableColumns(): array
    {
        return ['user_id', 'digest_mode'];
    }

    public function findForUser(int $userId): NotificationPreferences
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $userId],
        );

        if ($row === null) {
            return NotificationPreferences::defaults($userId);
        }

        return new NotificationPreferences(
            $userId,
            NotificationPreferences::parseLeadDays((string) $row['lead_days']),
            DigestMode::tryFromString((string) $row['digest_mode']) ?? DigestMode::Immediate,
            (int) $row['digest_day'],
            $this->db->platform()->toBoolean($row['price_change_alerts'] ?? true),
            $this->db->platform()->toBoolean($row['budget_alerts'] ?? true),
            $this->db->platform()->toBoolean($row['contract_alerts'] ?? true),
        );
    }

    public function save(NotificationPreferences $preferences): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $affected = $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('lead_days') . ' = :lead,'
            . ' ' . $this->quote('digest_mode') . ' = :mode,'
            . ' ' . $this->quote('digest_day') . ' = :day,'
            . ' ' . $this->quote('price_change_alerts') . ' = :price_change,'
            . ' ' . $this->quote('budget_alerts') . ' = :budget,'
            . ' ' . $this->quote('contract_alerts') . ' = :contract,'
            . ' ' . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('user_id') . ' = :user',
            [
                'lead' => $preferences->leadDaysAsString(),
                'mode' => $preferences->digestMode->value,
                'day' => $preferences->digestDay,
                'price_change' => $this->db->platform()->booleanParameter($preferences->priceChangeAlerts),
                'budget' => $this->db->platform()->booleanParameter($preferences->budgetAlerts),
                'contract' => $this->db->platform()->booleanParameter($preferences->contractAlerts),
                'now' => $now,
                'user' => $preferences->userId,
            ],
        );

        if ($affected > 0) {
            return;
        }

        // MySQL reports zero affected rows for an update that changes nothing,
        // so a missing row and an unchanged one look alike here. Checking which
        // it was costs one cheap SELECT and avoids an insert that would violate
        // the primary key.
        $exists = $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $preferences->userId],
        );

        if ($exists !== null) {
            return;
        }

        $this->db->insert($this->table(), [
            'user_id' => $preferences->userId,
            'lead_days' => $preferences->leadDaysAsString(),
            'digest_mode' => $preferences->digestMode->value,
            'digest_day' => $preferences->digestDay,
            'price_change_alerts' => $this->db->platform()->booleanParameter($preferences->priceChangeAlerts),
            'budget_alerts' => $this->db->platform()->booleanParameter($preferences->budgetAlerts),
            'contract_alerts' => $this->db->platform()->booleanParameter($preferences->contractAlerts),
            'created_at' => $now,
            'updated_at' => $now,
        ], 'user_id');
    }
}
