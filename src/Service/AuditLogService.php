<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\AuditEntry;
use App\Domain\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\MembershipRepository;
use App\Security\RequestContextHolder;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writing and reading the audit trail.
 *
 * Every call site is a service, never a controller: what happened is a property
 * of the operation, not of the transport that asked for it, and the API phase
 * has to produce the same entries as the web UI without a second set of call
 * sites to remember.
 *
 * Recording is deliberately non-fatal. If the log write fails — the table is
 * full, the column is short, the connection dropped — the user's sign-in or
 * password change still completes and the failure goes to the application log.
 * The alternative is an audit trail that can take the whole application down,
 * which is a worse property than an audit trail with a gap in it that the
 * application log explains.
 */
final class AuditLogService
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly MembershipRepository $memberships,
        private readonly RequestContextHolder $requestContext,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record an event performed by a signed-in user.
     *
     * The household is resolved from the actor's membership when one is not
     * given, so an Owner reviewing their household's log sees the event without
     * every call site having to remember to pass an id.
     *
     * @param array<string, mixed> $context
     */
    public function record(
        AuditAction $action,
        ?User $actor = null,
        array $context = [],
        ?int $householdId = null,
        ?int $targetUserId = null,
        ?string $targetLabel = null,
    ): void {
        $this->write(
            $action,
            $actor?->id,
            $actor?->email,
            $targetUserId ?? $actor?->id,
            $targetLabel ?? $actor?->email,
            $householdId ?? ($actor !== null ? $this->primaryHouseholdId($actor->id) : null),
            $context,
        );
    }

    /**
     * Record an event that has no signed-in actor — a failed sign-in, or one
     * blocked by the throttle.
     *
     * The attempted address is stored as the actor label even when no account
     * matches it. That is the entry's whole value: "somebody tried this address
     * eleven times" is only visible if the address is kept.
     *
     * @param array<string, mixed> $context
     */
    public function recordAnonymous(
        AuditAction $action,
        string $attemptedIdentifier,
        ?User $target = null,
        array $context = [],
    ): void {
        $this->write(
            $action,
            $target?->id,
            $attemptedIdentifier,
            $target?->id,
            $target === null ? $attemptedIdentifier : $target->email,
            $target === null ? null : $this->primaryHouseholdId($target->id),
            $context,
        );
    }

    /**
     * Record an anonymous event at most once per address per window: the
     * first occurrence writes the entry, and each later one raises its
     * `count` (Phase 42). What a scan of an endpoint needs to leave in the
     * log is that it happened and how often, not a row per attempt.
     *
     * @param array<string, mixed> $context
     * @param int $count Occurrences in this window so far, this one included.
     */
    public function recordRepeated(
        AuditAction $action,
        string $attemptedIdentifier,
        array $context,
        DateTimeImmutable $windowStart,
        int $count,
    ): void {
        if ($count > 1) {
            try {
                $id = $this->repository->latestIdFrom($action, $this->requestContext->get()->ipAddress, $windowStart);
                if ($id !== null) {
                    $this->repository->setContextValue($id, 'count', $count);

                    return;
                }
            } catch (Throwable $exception) {
                $this->logger->error('Could not update an audit entry', [
                    'action' => $action->value,
                    'exception' => $exception->getMessage(),
                ]);

                return;
            }
        }

        // The first in the window — or the entry it would raise went missing.
        $this->recordAnonymous($action, $attemptedIdentifier, null, $context + ['count' => $count]);
    }

    /**
     * @param list<AuditAction> $actions
     * @return list<AuditEntry>
     */
    public function page(Scope $scope, array $actions = [], int $page = 1, int $perPage = 50): array
    {
        $values = array_map(static fn (AuditAction $action): string => $action->value, $actions);

        return $scope->isInstanceAdmin
            ? $this->repository->findForInstance($scope, $values, $page, $perPage)
            : $this->repository->findForHousehold($scope, $values, $page, $perPage);
    }

    /**
     * @param list<AuditAction> $actions
     */
    public function count(Scope $scope, array $actions = []): int
    {
        $values = array_map(static fn (AuditAction $action): string => $action->value, $actions);

        return $scope->isInstanceAdmin
            ? $this->repository->countForInstance($scope, $values)
            : $this->repository->countForHousehold($scope, $values);
    }

    public function purge(DateTimeImmutable $cutoff): int
    {
        return $this->repository->purgeOlderThan($cutoff);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(
        AuditAction $action,
        ?int $actorUserId,
        ?string $actorLabel,
        ?int $targetUserId,
        ?string $targetLabel,
        ?int $householdId,
        array $context,
    ): void {
        $origin = $this->requestContext->get();

        try {
            $this->repository->append(
                $action,
                $this->clock->now(),
                $actorUserId,
                $actorLabel,
                $targetUserId,
                $targetLabel,
                $householdId,
                $origin->ipAddress,
                $origin->userAgent,
                $context,
            );
        } catch (Throwable $exception) {
            $this->logger->error('Could not write an audit entry', [
                'action' => $action->value,
                'actor_user_id' => $actorUserId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function primaryHouseholdId(int $userId): ?int
    {
        $memberships = $this->memberships->findAllForUser($userId);

        return $memberships === [] ? null : $memberships[0]->householdId;
    }
}
