<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AlertType;
use App\Persistence\Database;
use App\Support\Clock;

/**
 * Which of a user's channels each alert type goes to.
 *
 * The absence of any row for a user means "everything, everywhere" — see the
 * migration for why. That default lives in the service rather than here: this
 * class reports what is stored, and stored nothing is a fact, not a policy.
 */
final class NotificationRouteRepository extends AbstractRepository
{
    /**
     * Stored for a channel the user has ticked nothing for. It is not an
     * `AlertType`, so it never matches an alert; its job is to keep the
     * channel's mute in the table, so an empty grid is not read as "never
     * configured" and deleting another channel does not turn this one back on.
     */
    public const NONE = 'none';

    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'notification_routes';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'user_id', 'channel_id', 'alert_type'];
    }

    /**
     * Every route for a user, as channel id => list of alert types. A channel
     * the user muted entirely is present with an empty list, so "nothing
     * stored" and "everything switched off" stay distinguishable.
     *
     * @return array<int, list<string>>
     */
    public function findForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('channel_id') . ', ' . $this->quote('alert_type')
            . ' FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $userId],
        );

        $routes = [];
        foreach ($rows as $row) {
            $channelId = (int) $row['channel_id'];
            $routes[$channelId] ??= [];

            if ((string) $row['alert_type'] !== self::NONE) {
                $routes[$channelId][] = (string) $row['alert_type'];
            }
        }

        return $routes;
    }

    /**
     * Replace a user's routing wholesale.
     *
     * A delete and re-insert rather than a diff: the form always submits the
     * complete picture, and reconstructing it is both simpler and immune to a
     * half-applied change leaving somebody subscribed to something they just
     * turned off.
     *
     * @param array<int, list<AlertType>> $routes Channel id => alert types. A
     *        channel with an empty list is stored as muted.
     */
    public function replaceForUser(int $userId, array $routes): void
    {
        $this->db->transactional(function () use ($userId, $routes): void {
            $this->db->execute(
                'DELETE FROM ' . $this->quote($this->table())
                . ' WHERE ' . $this->quote('user_id') . ' = :user',
                ['user' => $userId],
            );

            $now = $this->clock->now()->format('Y-m-d H:i:s');

            foreach ($routes as $channelId => $types) {
                if ($types === []) {
                    $this->db->insert($this->table(), [
                        'user_id' => $userId,
                        'channel_id' => $channelId,
                        'alert_type' => self::NONE,
                        'created_at' => $now,
                    ]);

                    continue;
                }

                foreach ($types as $type) {
                    $this->db->insert($this->table(), [
                        'user_id' => $userId,
                        'channel_id' => $channelId,
                        'alert_type' => $type->value,
                        'created_at' => $now,
                    ]);
                }
            }
        });
    }
}
