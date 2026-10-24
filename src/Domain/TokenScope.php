<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One thing an API token may be used for: a resource, and read or write.
 *
 * A scope narrows and never grants, as the read/write ability before it did.
 * The request still runs under the bearer's household role, so a token holding
 * `subscriptions:write` that belongs to a Viewer writes nothing — it passes
 * this check and fails the role's.
 *
 * Two resource groups rather than one per endpoint: a scope list a person has
 * to choose from when issuing a token is only useful while it is short. The
 * names are `<resource>:<access>` so they could become OAuth scopes later
 * without being renamed.
 */
enum TokenScope: string
{
    case SubscriptionsRead = 'subscriptions:read';
    case SubscriptionsWrite = 'subscriptions:write';
    case CatalogueRead = 'catalogue:read';
    case CatalogueWrite = 'catalogue:write';
    case CalendarRead = 'calendar:read';

    public function resource(): string
    {
        return explode(':', $this->value, 2)[0];
    }

    public function isWrite(): bool
    {
        return str_ends_with($this->value, ':write');
    }

    /**
     * Whether holding this scope meets a route that needs `$needed`.
     *
     * `:write` implies `:read` for the same resource, and nothing else implies
     * anything: write access to subscriptions says nothing about categories.
     */
    public function satisfies(self $needed): bool
    {
        if ($this === $needed) {
            return true;
        }

        return $this->isWrite() && !$needed->isWrite() && $this->resource() === $needed->resource();
    }

    public function labelKey(): string
    {
        return 'token_scope.' . str_replace(':', '_', $this->value);
    }
}
