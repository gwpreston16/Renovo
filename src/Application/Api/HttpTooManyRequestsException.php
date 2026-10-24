<?php

declare(strict_types=1);

namespace App\Application\Api;

use Slim\Exception\HttpSpecializedException;

/**
 * 429 Too Many Requests. Slim has no class of its own for it; the error
 * handler renders any HTTP exception by its code, so this is all it takes.
 */
final class HttpTooManyRequestsException extends HttpSpecializedException
{
    /** @var int */
    protected $code = 429;

    /** @var string */
    protected $message = 'Too many requests.';

    protected string $title = '429 Too Many Requests';
    protected string $description = 'The client has sent too many requests in a given amount of time.';
}
