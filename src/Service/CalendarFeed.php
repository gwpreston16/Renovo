<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One built calendar feed: the text a client downloads, and the validator
 * that lets a client which already holds it be told so with a 304 (K259).
 *
 * The ETag is weak (`W/"…"`) because the bodies it stands for are not
 * byte-for-byte equal: every event's DTSTAMP is the time of the poll, and
 * the hash leaves those lines out. Everything else the feed is made from —
 * the scope's rows, the locale, the instance name, today's date — reaches
 * the body and so the hash.
 */
final class CalendarFeed
{
    public function __construct(
        public readonly string $body,
        public readonly string $etag,
    ) {
    }

    /**
     * Whether an `If-None-Match` header names this feed, so the poll can be
     * answered 304.
     *
     * RFC 9110 §13.1.2: the header is `*` or a comma-separated list of entity
     * tags, compared weakly — `W/"x"` and `"x"` are the same tag — because a
     * client sends back whichever form it was given, and some drop the `W/`.
     */
    public function matches(string $ifNoneMatch): bool
    {
        $mine = self::opaque($this->etag);
        foreach (explode(',', $ifNoneMatch) as $tag) {
            $tag = trim($tag);
            if ($tag === '*' || ($tag !== '' && self::opaque($tag) === $mine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An entity tag without its weakness marker, for a weak comparison.
     */
    private static function opaque(string $tag): string
    {
        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }
}
