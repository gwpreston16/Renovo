<?php

declare(strict_types=1);

namespace App\Http;

/**
 * What the request being made is for, and who asked for it.
 *
 * The client cannot know either: it is handed a URL. A caller that wants a
 * refusal attributed — logo fetch, rates, update check, a channel — runs its
 * request inside `within()`, and the refusal recorder reads the answer back.
 * A request made outside any call, by the scheduler for instance, is recorded
 * without a user.
 */
final class OutboundContext
{
    public const LOGO = 'logo';
    public const RATES = 'rates';
    public const UPDATES = 'updates';
    public const BACKUP = 'backup';

    private ?string $feature = null;

    private ?int $actorId = null;

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function within(string $feature, ?int $actorId, callable $work): mixed
    {
        $before = [$this->feature, $this->actorId];
        [$this->feature, $this->actorId] = [$feature, $actorId];

        try {
            return $work();
        } finally {
            [$this->feature, $this->actorId] = $before;
        }
    }

    public function feature(): ?string
    {
        return $this->feature;
    }

    public function actorId(): ?int
    {
        return $this->actorId;
    }
}
