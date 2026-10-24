<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\I18n\Translator;
use App\Service\RateLimitDecision;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpSpecializedException;

/**
 * 429 Too Many Requests. Slim has no class of its own for it; the error
 * handler renders any HTTP exception by its code.
 */
final class HttpTooManyRequestsException extends HttpSpecializedException
{
    /** @var int */
    protected $code = 429;

    /** @var string */
    protected $message = 'Too many requests.';

    protected string $title = '429 Too Many Requests';
    protected string $description = 'The client has sent too many requests in a given amount of time.';

    /**
     * The refusal for a request over its limit, in the reader's language. The
     * headers, Retry-After among them, are added on the way out by
     * RateLimitHeadersMiddleware: an exception cannot carry them.
     */
    public static function for(
        ServerRequestInterface $request,
        RateLimitDecision $decision,
        Translator $translator,
    ): self {
        $exception = new self(
            $request,
            $translator->trans('error.api.rate_limited', ['seconds' => $decision->resetSeconds]),
        );
        $exception->setTitle($translator->trans('error.page.rate_limited_title'));

        return $exception;
    }
}
