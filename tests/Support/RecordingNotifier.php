<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\User;
use App\Notification\Alert;
use App\Notification\ChannelField;
use App\Notification\Notifier;
use App\Notification\NotifierException;

/**
 * A channel that delivers to an array.
 *
 * Used wherever the question is "was this sent, once, to the right place"
 * rather than "what did the request look like". It can also be told to fail,
 * which is how the ledger's retry behaviour is tested without an unreliable
 * third party.
 */
final class RecordingNotifier implements Notifier
{
    /** @var list<array{alert: Alert, channel: NotificationChannel, user: User}> */
    public array $sent = [];

    public int $failures = 0;

    /** Called as each message is sent, for a test to look at what surrounds a delivery. */
    public ?\Closure $onSend = null;

    public function __construct(
        private readonly string $key = 'recording',
        private bool $shouldFail = false,
    ) {
    }

    public function failNext(bool $fail = true): void
    {
        $this->shouldFail = $fail;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Recording';
    }

    public function fields(): array
    {
        return [ChannelField::optional('target', 'Target')];
    }

    public function normaliseConfig(array $input, array $existing = []): array
    {
        return ['target' => trim($input['target'] ?? '')];
    }

    public function describe(array $config): string
    {
        return $config['target'] ?? 'recording';
    }

    public function send(NotificationChannel $channel, Alert $alert, User $recipient): void
    {
        if ($this->shouldFail) {
            $this->failures++;

            throw NotifierException::transport($this->label(), 'deliberately unavailable');
        }

        if ($this->onSend !== null) {
            ($this->onSend)();
        }

        $this->sent[] = ['alert' => $alert, 'channel' => $channel, 'user' => $recipient];
    }

    /**
     * @return list<string>
     */
    public function titles(): array
    {
        return array_map(static fn (array $entry): string => $entry['alert']->title, $this->sent);
    }

    /**
     * @return list<string>
     */
    public function alertTypes(): array
    {
        return array_map(static fn (array $entry): string => $entry['alert']->type->value, $this->sent);
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->failures = 0;
    }
}
