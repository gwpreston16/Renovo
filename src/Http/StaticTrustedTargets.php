<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A fixed list of trusted targets, for tests and for the empty default.
 */
final class StaticTrustedTargets implements TrustedTargets
{
    /**
     * @param list<string> $entries Trusted: may be private, may be plain http.
     * @param list<string> $contacts May be contacted under the allowlist, and
     *        nothing more.
     */
    public function __construct(private readonly array $entries = [], private readonly array $contacts = [])
    {
    }

    public function entries(): array
    {
        return $this->entries;
    }

    public function allowlisted(): array
    {
        return array_values(array_merge($this->entries, $this->contacts));
    }
}
