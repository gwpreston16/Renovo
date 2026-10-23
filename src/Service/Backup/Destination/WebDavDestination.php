<?php

declare(strict_types=1);

namespace App\Service\Backup\Destination;

use DOMDocument;
use DOMXPath;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * A WebDAV folder (Phase 39): Nextcloud, ownCloud, most NAS boxes.
 *
 * `PUT` the file, then `PROPFIND` it and compare the size the server reports
 * with the size sent: WebDAV has no checksum every server agrees on, and a
 * size that matches after a PUT that succeeded is the confirmation it has.
 */
final class WebDavDestination implements BackupDestination
{
    use RoundTripTest;

    private const PROPFIND = '<?xml version="1.0" encoding="utf-8"?>'
        . '<d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/></d:prop></d:propfind>';

    public function __construct(
        private readonly HttpExchange $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly string $folderUrl,
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
    ) {
    }

    public function put(string $localPath, string $object, string $sha256, int $size): void
    {
        $request = $this->request('PUT', $object)->withHeader('Content-Type', 'application/octet-stream');
        $response = $this->http->send($request, DestinationException::STEP_WRITE, $localPath);

        if (!in_array($response->getStatusCode(), [200, 201, 204], true)) {
            throw $this->failure(DestinationException::STEP_WRITE, $response);
        }

        $stored = $this->head($object);
        if ($stored !== $size) {
            // Not left behind under the name of a good copy.
            try {
                $this->delete($object);
            } catch (DestinationException) {
            }

            throw new DestinationException(DestinationException::STEP_VERIFY, 'size', sprintf(
                'The server holds %s byte(s) where %d were sent.',
                $stored === null ? 'no' : (string) $stored,
                $size,
            ));
        }
    }

    public function head(string $object): ?int
    {
        $request = $this->request('PROPFIND', $object)
            ->withHeader('Depth', '0')
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withBody($this->streams->createStream(self::PROPFIND));
        $response = $this->http->send($request, DestinationException::STEP_READ);

        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 207) {
            throw $this->failure(DestinationException::STEP_READ, $response);
        }

        $length = self::contentLength((string) $response->getBody());
        if ($length === null) {
            throw new DestinationException(
                DestinationException::STEP_READ,
                'unreadable',
                'The server did not report the stored size.',
            );
        }

        return $length;
    }

    public function get(string $object, string $localPath): void
    {
        $response = $this->http->send(
            $this->request('GET', $object),
            DestinationException::STEP_READ,
            null,
            $localPath,
        );

        if ($response->getStatusCode() !== 200) {
            throw $this->failure(DestinationException::STEP_READ, $response);
        }
    }

    public function delete(string $object): void
    {
        $response = $this->http->send($this->request('DELETE', $object), DestinationException::STEP_DELETE);

        if (!in_array($response->getStatusCode(), [200, 204, 404], true)) {
            throw $this->failure(DestinationException::STEP_DELETE, $response);
        }
    }

    public function url(string $object): string
    {
        return rtrim($this->folderUrl, '/') . '/' . rawurlencode($object);
    }

    /**
     * `getcontentlength` from a multistatus answer, in the DAV namespace
     * whatever prefix the server gave it. Read without the network and
     * without entities: it is the far end's document.
     */
    public static function contentLength(string $xml): ?int
    {
        if ($xml === '') {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');
        $value = trim((string) $xpath->evaluate('string(//d:response[1]//d:getcontentlength)'));

        return preg_match('/^\d{1,19}$/', $value) === 1 ? (int) $value : null;
    }

    private function request(string $method, string $object): RequestInterface
    {
        return $this->requests->createRequest($method, $this->url($object))
            ->withHeader('Authorization', 'Basic ' . base64_encode($this->username . ':' . $this->password));
    }

    private function failure(string $step, ResponseInterface $response): DestinationException
    {
        $status = $response->getStatusCode();

        return new DestinationException($step, match (true) {
            $status === 404 => 'not_found',
            $status === 401 || $status === 403 => 'denied',
            $status === 507 => 'full',
            default => 'refused',
        }, sprintf('The WebDAV server answered %d.', $status));
    }
}
