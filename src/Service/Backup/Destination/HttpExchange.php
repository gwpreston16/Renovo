<?php

declare(strict_types=1);

namespace App\Service\Backup\Destination;

use App\Http\BlockedTargetException;
use App\Http\GuardedTransferClient;
use App\Http\HttpClientException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sending one request to an HTTP destination, and turning what can go wrong
 * on the way into a `DestinationException` (Phase 39).
 *
 * The guard's refusal and the transport's failure become `connect`; a
 * redirect, which a transfer never follows, is refused with where it
 * pointed, so the administrator can enter that address instead.
 */
final class HttpExchange
{
    public function __construct(private readonly GuardedTransferClient $client)
    {
    }

    /**
     * @throws DestinationException
     */
    public function send(
        RequestInterface $request,
        string $step,
        ?string $uploadFrom = null,
        ?string $downloadTo = null,
    ): ResponseInterface {
        try {
            $response = $this->client->send($request, $uploadFrom, $downloadTo);
        } catch (BlockedTargetException $exception) {
            throw new DestinationException(DestinationException::STEP_CONNECT, 'blocked', $exception->getMessage());
        } catch (HttpClientException $exception) {
            throw new DestinationException(DestinationException::STEP_CONNECT, 'transport', $exception->getMessage());
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            throw new DestinationException($step, 'redirect', sprintf(
                'The destination answered %d, pointing to %s. Renovo does not follow a redirect to a backup'
                . ' destination: enter the address it points to.',
                $status,
                self::printable($response->getHeaderLine('Location')),
            ));
        }

        return $response;
    }

    /**
     * A value from the far end, safe to put in a message: printable ASCII
     * only, and short.
     */
    public static function printable(string $value, int $length = 200): string
    {
        $clean = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);

        return strlen($clean) > $length ? substr($clean, 0, $length) . '…' : $clean;
    }
}
