<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Captures outbound mail instead of sending it, so a test can read the
 * verification or reset link out of the message body.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    /** When true the relay refuses every message, as a down relay would. */
    public bool $failing = false;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->failing) {
            throw new \Symfony\Component\Mailer\Exception\TransportException('relay down');
        }

        $this->messages[] = $message;
    }

    public function lastBody(): string
    {
        $message = end($this->messages);
        if ($message === false) {
            return '';
        }

        // Mailer encodes the body as quoted-printable, which wraps long lines
        // with soft breaks — enough to split a hex token in half. Decode
        // before reading anything out of it.
        return quoted_printable_decode($message->toString());
    }

    /**
     * Pull a token out of the most recent message's links.
     */
    public function lastToken(): ?string
    {
        if (preg_match('/token=([A-Za-z0-9%]+)/', $this->lastBody(), $matches) === 1) {
            return urldecode($matches[1]);
        }

        return null;
    }

    /**
     * A token out of one particular message rather than the last.
     *
     * A single action can send two mails — a confirmation to a new address and
     * a warning to the old one — and it is the first that carries the link.
     */
    public function lastTokenFromMessage(int $index): ?string
    {
        $message = $this->messages[$index] ?? null;
        if ($message === null) {
            return null;
        }

        if (preg_match('/token=([A-Za-z0-9%]+)/', quoted_printable_decode($message->toString()), $m) === 1) {
            return urldecode($m[1]);
        }

        return null;
    }

    public function reset(): void
    {
        $this->messages = [];
    }
}
