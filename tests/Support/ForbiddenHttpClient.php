<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A client that must never be used: it counts the attempt and fails the test.
 *
 * The count is what a functional test asserts on, since the stack's error
 * handler would turn the failure into a 500 rather than let it reach PHPUnit.
 */
final class ForbiddenHttpClient implements ClientInterface
{
    public int $calls = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;

        Assert::fail('No request may be made here; one was sent to ' . $request->getUri() . '.');
    }
}
