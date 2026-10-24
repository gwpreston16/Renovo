<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Security\ContentSecurityPolicy;
use App\Security\CspNonce;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The security headers, on every response the application sends.
 *
 * Set here rather than only in the bundled nginx, so an operator who puts
 * their own proxy in front of the PHP container still gets them. Mounted
 * outside the error middleware, so a 404 or a 500 page carries them too.
 *
 * A header a controller has already set is left alone: an uploaded file is
 * sent with a stricter policy of its own (AttachmentResponse), and that must
 * win over the page policy written here.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * Off for everything a page has no use for; passkeys stay allowed, for
     * this origin only, or sign-in with one would stop working.
     */
    private const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), '
        . 'publickey-credentials-get=(self), publickey-credentials-create=(self)';

    private const ONE_YEAR = 31536000;

    public function __construct(
        private readonly CspNonce $nonce,
        private readonly string $appUrl,
        private readonly bool $hstsEnabled,
        private readonly bool $hstsIncludeSubdomains,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Renewed before the handler runs, so the template that renders the
        // page and the header written below share the one value.
        $nonce = $this->nonce->renew();

        $response = $handler->handle($request);

        $hsts = $this->hsts();

        $headers = [
            'Content-Security-Policy' => $this->isData($response)
                ? ContentSecurityPolicy::data()
                : ContentSecurityPolicy::page($nonce, $hsts !== null),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if ($hsts !== null) {
            $headers['Strict-Transport-Security'] = $hsts;
        }

        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * The HSTS value, or null when it must not be sent.
     *
     * Renovo has no trusted-proxy handling, so whether the browser reached it
     * over https can't be told from the request. The configured URL is the
     * signal instead, as it is for passkeys; a browser ignores HSTS received
     * over plain http in any case.
     */
    private function hsts(): ?string
    {
        if (!$this->hstsEnabled || !str_starts_with(strtolower($this->appUrl), 'https://')) {
            return null;
        }

        return 'max-age=' . self::ONE_YEAR . ($this->hstsIncludeSubdomains ? '; includeSubDomains' : '');
    }

    private function isData(ResponseInterface $response): bool
    {
        $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        return $type === 'application/json' || str_ends_with($type, '+json');
    }
}
