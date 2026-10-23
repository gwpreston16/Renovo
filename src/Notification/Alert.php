<?php

declare(strict_types=1);

namespace App\Notification;

use App\Domain\AlertType;
use DateTimeImmutable;

/**
 * One thing worth telling somebody, in a form every channel can render.
 *
 * An alert carries both what to say and who it is about, and the second part
 * is what the ledger keys on. `subjectType`, `subjectId` and `occurrenceKey`
 * together are the alert's identity: send the same identity twice and the
 * second send is suppressed, which is the whole of the idempotency guarantee.
 *
 * The body is a list of lines rather than a formatted string because the
 * channels disagree about formatting — email wants paragraphs, Gotify wants
 * plain text, Slack wants its own markup. Handing each of them the same
 * pre-formatted blob would mean the ugliest renderer decides how all of them
 * look.
 */
final class Alert
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public readonly AlertType $type,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly string $occurrenceKey,
        public readonly string $title,
        public readonly array $lines = [],
        public readonly ?string $url = null,
        public readonly ?DateTimeImmutable $dueDate = null,
        public readonly int $priority = 5,
    ) {
    }

    public const SUBJECT_SUBSCRIPTION = 'subscription';
    public const SUBJECT_BUDGET = 'budget';
    public const SUBJECT_DIGEST = 'digest';
    public const SUBJECT_TEST = 'test';
    public const SUBJECT_BACKUP = 'backup';

    public function body(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * The alert as a single line, for a digest that lists many of them.
     */
    public function summaryLine(): string
    {
        return $this->lines === [] ? $this->title : $this->title . ' — ' . $this->lines[0];
    }

    /**
     * @param list<string> $lines
     */
    public function withLines(array $lines): self
    {
        return new self(
            $this->type,
            $this->subjectType,
            $this->subjectId,
            $this->occurrenceKey,
            $this->title,
            $lines,
            $this->url,
            $this->dueDate,
            $this->priority,
        );
    }
}
