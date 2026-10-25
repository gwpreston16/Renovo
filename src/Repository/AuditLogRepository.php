<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AuditAction;
use App\Domain\Entity\AuditEntry;
use App\Persistence\Criteria;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use DateTimeImmutable;

/**
 * The audit trail.
 *
 * Reading it is scoped like everything else, and the two ways of reading it are
 * separate methods rather than one method with a flag:
 *
 *  - `findForHousehold()` uses the standard scope predicate, so a household
 *    Owner sees their household's events and nothing else. `ownerColumn()` is
 *    null because an audit entry is not personal property: in ISOLATED mode an
 *    Owner still needs to see that somebody else's sign-in happened, and the
 *    entry carries no financial data to isolate.
 *  - `findForInstance()` is the instance-administrator view, and it asserts the
 *    flag itself. It has to be a distinct path — the scope predicate degrades
 *    to `1 = 0` for an admin with no household, which is correct for
 *    subscription data and useless for a log that is explicitly instance-wide.
 *    Putting the check here rather than trusting the route means a future
 *    caller that forgets the middleware gets an exception, not a data leak.
 *
 * Writing is unscoped on purpose. An audit entry records what happened,
 * including a failed sign-in by somebody with no session, no household and no
 * scope at all; making the write depend on the actor's scope would mean the
 * events most worth recording are the ones that cannot be.
 */
final class AuditLogRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'audit_log';
    }

    /**
     * An audit entry belongs to a household, never to a member of one.
     */
    protected function ownerColumn(): ?string
    {
        return null;
    }

    protected function filterableColumns(): array
    {
        return ['id', 'occurred_at', 'action', 'actor_user_id', 'target_user_id', 'household_id'];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function append(
        AuditAction $action,
        DateTimeImmutable $occurredAt,
        ?int $actorUserId,
        ?string $actorLabel,
        ?int $targetUserId,
        ?string $targetLabel,
        ?int $householdId,
        ?string $ipAddress,
        ?string $userAgent,
        array $context,
    ): int {
        return $this->db->insert('audit_log', [
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
            'action' => $action->value,
            'actor_user_id' => $actorUserId,
            'actor_label' => $actorLabel === null ? null : mb_substr($actorLabel, 0, 254),
            'target_user_id' => $targetUserId,
            'target_label' => $targetLabel === null ? null : mb_substr($targetLabel, 0, 254),
            'household_id' => $householdId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Instance-wide, for an instance administrator.
     *
     * @param list<string> $actions
     * @return list<AuditEntry>
     * @throws ScopeViolationException when the scope is not an administrator's.
     */
    public function findForInstance(Scope $scope, array $actions = [], int $page = 1, int $perPage = 50): array
    {
        $this->assertInstanceAdmin($scope);

        $params = [];
        $criteria = $this->criteriaFor($actions)->paginate($page, $perPage);
        $conditions = $this->compileConditions($criteria, $params);

        $sql = $this->select()
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
            . ' ORDER BY ' . $this->qualify('occurred_at') . ' DESC, ' . $this->qualify('id') . ' DESC'
            . $this->compileLimit($criteria, $params);

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    /**
     * @param list<string> $actions
     * @throws ScopeViolationException
     */
    public function countForInstance(Scope $scope, array $actions = []): int
    {
        $this->assertInstanceAdmin($scope);

        $params = [];
        $conditions = $this->compileConditions($this->criteriaFor($actions), $params);

        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('audit_log')
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions)),
            $params,
        );
    }

    /**
     * The household Owner's view: scope-limited, through the standard
     * predicate.
     *
     * @param list<string> $actions
     * @return list<AuditEntry>
     */
    public function findForHousehold(Scope $scope, array $actions = [], int $page = 1, int $perPage = 50): array
    {
        $params = [];
        $criteria = $this->criteriaFor($actions)->paginate($page, $perPage);

        $sql = $this->select()
            . $this->scopedWhere($scope, $criteria, $params)
            . ' ORDER BY ' . $this->qualify('occurred_at') . ' DESC, ' . $this->qualify('id') . ' DESC'
            . $this->compileLimit($criteria, $params);

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    /**
     * @param list<string> $actions
     */
    public function countForHousehold(Scope $scope, array $actions = []): int
    {
        $params = [];
        $where = $this->scopedWhere($scope, $this->criteriaFor($actions), $params);

        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('audit_log') . $where,
            $params,
        );
    }

    /**
     * The newest entry of an action from one address since a moment, for an
     * event recorded once per window (Phase 42's failed token
     * authentication).
     */
    public function latestIdFrom(AuditAction $action, string $ipAddress, DateTimeImmutable $since): ?int
    {
        $id = $this->db->fetchValue(
            'SELECT MAX(' . $this->quote('id') . ') FROM ' . $this->quote('audit_log')
            . ' WHERE ' . $this->quote('action') . ' = :action'
            . ' AND ' . $this->quote('ip_address') . ' = :ip'
            . ' AND ' . $this->quote('occurred_at') . ' >= :since',
            ['action' => $action->value, 'ip' => $ipAddress, 'since' => $since->format('Y-m-d H:i:s')],
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * The entries of an action about one target label since a moment, newest
     * first, with their decoded context: what a repeated event is matched
     * against (Phase 43's outbound refusals). A host has a handful of
     * distinct causes in an hour, so the match on the rest of the context is
     * made in code, not in SQL that differs between the two engines.
     *
     * @return list<array{id: int, context: array<string, mixed>}>
     */
    public function recentFor(AuditAction $action, string $targetLabel, DateTimeImmutable $since): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('id') . ', ' . $this->quote('context') . ' FROM ' . $this->quote('audit_log')
            . ' WHERE ' . $this->quote('action') . ' = :action'
            . ' AND ' . $this->quote('target_label') . ' = :label'
            . ' AND ' . $this->quote('occurred_at') . ' >= :since'
            . ' ORDER BY ' . $this->quote('id') . ' DESC',
            [
                'action' => $action->value,
                'label' => mb_substr($targetLabel, 0, 254),
                'since' => $since->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(static function (array $row): array {
            $context = is_string($row['context'] ?? null) ? json_decode((string) $row['context'], true) : [];

            return ['id' => (int) $row['id'], 'context' => is_array($context) ? $context : []];
        }, $rows);
    }

    /**
     * Set one value in an entry's context, keeping the rest — the count on a
     * repeated event. The only change an entry ever takes after it is
     * written, and only to say how many times the same thing happened.
     */
    public function setContextValue(int $id, string $key, int|string $value): void
    {
        $raw = $this->db->fetchValue(
            'SELECT ' . $this->quote('context') . ' FROM ' . $this->quote('audit_log')
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $id],
        );

        $context = is_string($raw) ? json_decode($raw, true) : [];
        $context = is_array($context) ? $context : [];
        $context[$key] = $value;

        $this->db->execute(
            'UPDATE ' . $this->quote('audit_log') . ' SET ' . $this->quote('context') . ' = :context'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['context' => json_encode($context, JSON_THROW_ON_ERROR), 'id' => $id],
        );
    }

    /**
     * Housekeeping: the log is evidence, not an archive, and an instance that
     * has run for years should not be carrying every sign-in since it started.
     */
    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote('audit_log') . ' WHERE ' . $this->quote('occurred_at') . ' < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @param list<string> $actions
     */
    private function criteriaFor(array $actions): Criteria
    {
        $criteria = Criteria::new();

        return $actions === [] ? $criteria : $criteria->in('action', $actions);
    }

    private function assertInstanceAdmin(Scope $scope): void
    {
        if (!$scope->isInstanceAdmin) {
            throw new ScopeViolationException('Only an instance administrator may read the instance audit log.');
        }
    }

    private function select(): string
    {
        return 'SELECT * FROM ' . $this->quote('audit_log');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AuditEntry
    {
        $context = [];
        if (is_string($row['context'] ?? null) && $row['context'] !== '') {
            $decoded = json_decode((string) $row['context'], true);
            $context = is_array($decoded) ? $decoded : [];
        }

        return new AuditEntry(
            id: (int) $row['id'],
            occurredAt: new DateTimeImmutable((string) $row['occurred_at']),
            action: AuditAction::from((string) $row['action']),
            actorUserId: isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null,
            actorLabel: isset($row['actor_label']) ? (string) $row['actor_label'] : null,
            targetUserId: isset($row['target_user_id']) ? (int) $row['target_user_id'] : null,
            targetLabel: isset($row['target_label']) ? (string) $row['target_label'] : null,
            householdId: isset($row['household_id']) ? (int) $row['household_id'] : null,
            ipAddress: isset($row['ip_address']) ? (string) $row['ip_address'] : null,
            userAgent: isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            context: $context,
        );
    }
}
