<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Domain\TrustedHostKind;
use App\Http\IpAddress;
use App\Http\TrustedTargets;
use App\Repository\TrustedHostRepository;
use Psr\Log\LoggerInterface;

/**
 * The administrator's list of destinations the SSRF guard will allow through.
 *
 * This is the one deliberate hole in the outbound network rules, so it is built
 * to be a hole somebody *chose*: every entry is added by an instance
 * administrator, every addition and removal is logged with who did it, and
 * nothing is on the list to begin with. The guard consults it; it never asks
 * the guard to relax anything else.
 *
 * Patterns are host names, dotted suffixes, bare addresses or CIDR blocks.
 * Anything else is refused here rather than being stored and silently ignored
 * by the guard — a rule an operator believes is in force but is not is worse
 * than no rule.
 */
final class TrustedHostService implements TrustedTargets
{
    /** @var list<string>|null */
    private ?array $cache = null;

    /** @var list<string>|null */
    private ?array $allCache = null;

    public function __construct(
        private readonly TrustedHostRepository $repository,
        private readonly AuditLogService $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Only the entries that lift the private-address and plain-http checks.
     */
    public function entries(): array
    {
        return $this->cache ??= $this->repository->patterns(TrustedHostKind::Private);
    }

    public function allowlisted(): array
    {
        return $this->allCache ??= $this->repository->patterns(null);
    }

    /**
     * @return list<array{id: int, pattern: string, kind: TrustedHostKind, note: string|null,
     *                    created_at: \DateTimeImmutable, created_by: string|null}>
     */
    public function all(): array
    {
        return $this->repository->findAll();
    }

    /**
     * @throws ValidationException
     */
    public function add(
        string $pattern,
        ?string $note,
        ?User $actor,
        TrustedHostKind $kind = TrustedHostKind::Private,
    ): void {
        $pattern = strtolower(trim($pattern));

        if ($pattern === '') {
            throw ValidationException::field('pattern', 'error.trusted_host.required');
        }

        if (mb_strlen($pattern) > 255) {
            throw ValidationException::field('pattern', 'error.trusted_host.too_long');
        }

        if (!$this->isValidPattern($pattern)) {
            throw ValidationException::field('pattern', 'error.trusted_host.invalid');
        }

        if ($this->repository->exists($pattern)) {
            throw ValidationException::field('pattern', 'error.trusted_host.duplicate');
        }

        $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 255);

        $this->repository->add($pattern, $note, $actor?->id, $kind);
        $this->cache = null;
        $this->allCache = null;

        $this->audit->record(AuditAction::TrustedHostAdded, $actor, ['pattern' => $pattern, 'kind' => $kind->value]);

        // Logged because this widens what the server can be made to connect to.
        // The record of who opened it, and when, is the only thing that makes
        // the exception reviewable afterwards.
        $this->logger->notice('Trusted host added', [
            'pattern' => $pattern,
            'user_id' => $actor?->id,
        ]);
    }

    public function remove(int $id, ?User $actor): void
    {
        $this->repository->delete($id);
        $this->cache = null;
        $this->allCache = null;

        $this->audit->record(AuditAction::TrustedHostRemoved, $actor, ['id' => $id]);

        $this->logger->notice('Trusted host removed', ['id' => $id, 'user_id' => $actor?->id]);
    }

    private function isValidPattern(string $pattern): bool
    {
        if (IpAddress::isValidRange($pattern)) {
            return true;
        }

        $host = ltrim($pattern, '.');

        // A host name, or a dotted suffix meaning "and anything under it".
        return $host !== ''
            && mb_strlen($host) <= 253
            && preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host) === 1;
    }
}
