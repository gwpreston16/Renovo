<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The one rule for a redirect target that came from the request: it is a path
 * on this site, or it is `/`.
 *
 * One leading slash and not two, and no backslash or control character
 * anywhere: browsers read `/\evil.example` and `/<tab>/evil.example` as
 * `//evil.example`, a URL on another host that merely begins like a path.
 * Anything with a scheme or host is refused for the same reason.
 */
final class SafeRedirectTarget
{
    public const FALLBACK = '/';

    /**
     * The target if it is a path on this site, otherwise null.
     */
    public static function path(string $candidate): ?string
    {
        if (
            $candidate === ''
            || $candidate[0] !== '/'
            || (isset($candidate[1]) && ($candidate[1] === '/' || $candidate[1] === '\\'))
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $candidate) === 1
        ) {
            return null;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        return $candidate;
    }

    /**
     * The target if it is a path on this site, otherwise `/`.
     */
    public static function orHome(string $candidate): string
    {
        return self::path($candidate) ?? self::FALLBACK;
    }
}
