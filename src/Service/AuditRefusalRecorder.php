<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\OutboundContext;
use App\Http\RefusalRecorder;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the HTTP layer's refusals to the audit log (Phase 43), adding what
 * the layer cannot know: which feature asked and which user it was for.
 *
 * A failure to record never stands in for the refusal it was recording — the
 * caller still gets its `BlockedTargetException`.
 */
final class AuditRefusalRecorder implements RefusalRecorder
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly OutboundContext $context,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function refused(string $host, string $reason): void
    {
        try {
            $actorId = $this->context->actorId();
            $actor = $actorId === null ? null : $this->users->findById($actorId);

            $this->audit->recordOutboundRefusal($host, $reason, $this->context->feature(), $actor);
        } catch (Throwable $exception) {
            $this->logger->error('Could not record a refused outbound request', [
                'reason' => $reason,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
