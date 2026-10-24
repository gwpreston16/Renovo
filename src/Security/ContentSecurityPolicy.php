<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The policies Renovo sends, as strings.
 *
 * A page may run only script it served itself, or an inline block carrying
 * this response's nonce; no `eval`, no inline style, nothing from another
 * origin. Non-negotiable 8 says nothing is loaded from a third-party host, and
 * this is the browser enforcing it as well as the CI check.
 *
 * The seam for a later report endpoint is here: a `report-to` directive is one
 * more entry in `page()`, and nothing else has to change.
 */
final class ContentSecurityPolicy
{
    /**
     * For an HTML page.
     *
     * `img-src` stays `'self'` with no `data:`: every image is a served file
     * (the brand mark, the icon sprite, logos and avatars), and the Tailwind
     * build carries no data-URI backgrounds.
     */
    public static function page(string $nonce, bool $upgradeInsecureRequests): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $nonce . "'",
            "style-src 'self'",
            "img-src 'self'",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        // Only with HSTS: on a plain-http instance it would send every
        // subresource to an https port that isn't listening.
        if ($upgradeInsecureRequests) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    /**
     * For a JSON body, which a browser should never render or embed.
     */
    public static function data(): string
    {
        return "default-src 'none'; frame-ancestors 'none'";
    }

    /**
     * For a file a member uploaded, served back.
     *
     * `sandbox` gives the document an opaque origin with scripts off, so an
     * HTML or SVG file opened from Renovo cannot act as Renovo even if a
     * browser decides to render it.
     */
    public static function upload(): string
    {
        return "sandbox; default-src 'none'";
    }
}
