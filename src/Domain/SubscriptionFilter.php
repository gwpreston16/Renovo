<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The list view's filter, sort and paging state, parsed once from the query
 * string and passed around as a value object.
 *
 * Sort keys are symbolic; the repository maps them to real columns through its
 * own allow-list, so an arbitrary `?sort=` value can never reach the SQL.
 */
final class SubscriptionFilter
{
    /** The highest page a request can ask for; the same bound as `Criteria::MAX_PAGE`. */
    public const MAX_PAGE = 1_000_000_000;

    public const SORTS = ['name', 'next_payment', 'price', 'created'];

    public const DEFAULT_PER_PAGE = 25;

    /**
     * @param list<int> $tagIds
     */
    public function __construct(
        public readonly string $search = '',
        public readonly ?int $categoryId = null,
        public readonly array $tagIds = [],
        public readonly ?int $ownerUserId = null,
        public readonly ?string $currency = null,
        public readonly ?SubscriptionType $type = null,
        public readonly bool $includeInactive = false,
        public readonly string $sort = 'next_payment',
        public readonly string $direction = 'asc',
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
        /**
         * One state only, from the status filter. Null is "every state the
         * rest of the filter admits".
         */
        public readonly ?SubscriptionStatus $status = null,
        /**
         * Whether cancelled rows come along with the paused ones when no
         * status is chosen. True by default so the API's `inactive=1` keeps
         * meaning "everything switched off"; the web list turns it off, and a
         * cancelled row is found there under its own filter.
         */
        public readonly bool $includeCancelled = true,
        /**
         * "Mine": the rows the viewer owns or has a share of a split in. A
         * narrowing of what the scope already admits, never a widening — the
         * repository applies it inside the scoped query, so it cannot reach a
         * row the viewer could not have paged to anyway.
         */
        public readonly bool $mine = false,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): self
    {
        $tagIds = [];
        $rawTags = $query['tag'] ?? null;
        foreach (is_array($rawTags) ? $rawTags : (is_scalar($rawTags) ? [$rawTags] : []) as $tag) {
            $id = (int) $tag;
            if ($id > 0) {
                $tagIds[] = $id;
            }
        }

        $sort = is_string($query['sort'] ?? null) ? $query['sort'] : 'next_payment';
        if (!in_array($sort, self::SORTS, true)) {
            $sort = 'next_payment';
        }

        $currency = is_string($query['currency'] ?? null) && $query['currency'] !== ''
            ? Currency::normalise($query['currency'])
            : null;

        return new self(
            search: is_string($query['q'] ?? null) ? trim($query['q']) : '',
            categoryId: self::positiveIntOrNull($query['category'] ?? null),
            tagIds: $tagIds,
            ownerUserId: self::positiveIntOrNull($query['owner'] ?? null),
            currency: $currency !== null && Currency::isValidCode($currency) ? $currency : null,
            type: SubscriptionType::tryFrom(is_string($query['type'] ?? null) ? $query['type'] : ''),
            includeInactive: ($query['inactive'] ?? '') === '1',
            sort: $sort,
            direction: ($query['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            page: min(max(1, (int) ($query['page'] ?? 1)), self::MAX_PAGE),
            perPage: self::DEFAULT_PER_PAGE,
            status: SubscriptionStatus::tryFrom(is_string($query['status'] ?? null) ? $query['status'] : ''),
            mine: ($query['scope'] ?? '') === 'mine',
        );
    }

    /**
     * The same filter as the web list reads it: paused subscriptions included,
     * cancelled ones left to their own filter.
     *
     * The web list has no "include paused" control any more: it always shows
     * them, sunk to the bottom by the repository's ordering. A cancelled row is
     * finished rather than resting, and showing it among them would make the
     * list read as a history of everything ever tracked. The API keeps the
     * `inactive` parameter and its default, which is why this is a wither the
     * web controller applies rather than a new default on the constructor.
     */
    public function withIncludeInactive(): self
    {
        return $this->copy(includeInactive: true, includeCancelled: false);
    }

    private function copy(bool $includeInactive, bool $includeCancelled): self
    {
        return new self(
            search: $this->search,
            categoryId: $this->categoryId,
            tagIds: $this->tagIds,
            ownerUserId: $this->ownerUserId,
            currency: $this->currency,
            type: $this->type,
            includeInactive: $includeInactive,
            sort: $this->sort,
            direction: $this->direction,
            page: $this->page,
            perPage: $this->perPage,
            status: $this->status,
            includeCancelled: $includeCancelled,
            mine: $this->mine,
        );
    }

    /**
     * The same filter with no paging, for the callers that need every row it
     * matches rather than one page of them: the summary line's monthly total
     * and the CSV export.
     */
    public function unpaged(): self
    {
        return new self(
            search: $this->search,
            categoryId: $this->categoryId,
            tagIds: $this->tagIds,
            ownerUserId: $this->ownerUserId,
            currency: $this->currency,
            type: $this->type,
            includeInactive: $this->includeInactive,
            sort: $this->sort,
            direction: $this->direction,
            page: 1,
            perPage: 0,
            status: $this->status,
            includeCancelled: $this->includeCancelled,
            mine: $this->mine,
        );
    }

    public function isPaged(): bool
    {
        return $this->perPage > 0;
    }

    /**
     * Whether the user has narrowed the list, which is what decides between
     * "nothing matches" and "there is nothing here yet".
     *
     * `includeInactive` is deliberately not one of them. It widens the list
     * rather than narrowing it, so it can never be the reason a search came
     * back empty — and since the web list now sets it unconditionally,
     * counting it here would tell a household with no subscriptions at all
     * that its filters matched nothing.
     */
    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->categoryId !== null
            || $this->tagIds !== []
            || $this->ownerUserId !== null
            || $this->currency !== null
            || $this->type !== null
            || $this->status !== null
            || $this->mine;
    }

    /**
     * Rebuild the query string, overriding some parameters. Used by the sort
     * headers and pager links so they keep the rest of the filter intact.
     *
     * @param array<string, string|int|null> $overrides
     * @param list<int>|null $tagIds The tag chips to carry instead of this
     *        filter's own, for a chip link that toggles one of them.
     */
    public function toQueryString(array $overrides = [], ?array $tagIds = null): string
    {
        $params = array_filter([
            'q' => $this->search !== '' ? $this->search : null,
            'category' => $this->categoryId,
            'owner' => $this->ownerUserId,
            'currency' => $this->currency,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'scope' => $this->mine ? 'mine' : null,
            'inactive' => $this->includeInactive ? '1' : null,
            'sort' => $this->sort,
            'dir' => $this->direction,
            'page' => $this->page > 1 ? $this->page : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($params[$key]);
                continue;
            }
            $params[$key] = $value;
        }

        $query = http_build_query($params);
        foreach ($tagIds ?? $this->tagIds as $tagId) {
            $query .= ($query === '' ? '' : '&') . 'tag[]=' . $tagId;
        }

        return $query;
    }

    /**
     * The query a tag chip links to: this filter with that tag switched —
     * added when it is not chosen, taken away when it is — and back to the
     * first page, since the set of rows has changed under the pager.
     */
    public function queryTogglingTag(int $tagId): string
    {
        $tagIds = in_array($tagId, $this->tagIds, true)
            ? array_values(array_filter($this->tagIds, static fn (int $id): bool => $id !== $tagId))
            : [...$this->tagIds, $tagId];

        return $this->toQueryString(['page' => null], $tagIds);
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
