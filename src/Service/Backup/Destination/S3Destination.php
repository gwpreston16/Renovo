<?php

declare(strict_types=1);

namespace App\Service\Backup\Destination;

use App\Support\Clock;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * S3-compatible object storage (Phase 39): AWS S3, Backblaze B2 through its
 * S3 API, Cloudflare R2, Wasabi, MinIO.
 *
 * Single-PUT uploads, signed with SigV4 by {@see S3Signer}. The payload hash
 * signed into `x-amz-content-sha256` is the backup's SHA-256 recorded when it
 * verified, so the service itself refuses a body that arrives different —
 * a 200 is the destination's own checksum agreeing.
 *
 * Path-style (`endpoint/bucket/key`) for MinIO and most self-hosted stores;
 * virtual-hosted (`bucket.endpoint/key`) otherwise.
 */
final class S3Destination implements BackupDestination
{
    use RoundTripTest;

    private readonly S3Signer $signer;

    public function __construct(
        private readonly HttpExchange $http,
        private readonly RequestFactoryInterface $requests,
        private readonly Clock $clock,
        private readonly string $endpoint,
        string $region,
        private readonly string $bucket,
        private readonly string $prefix,
        private readonly bool $pathStyle,
        string $accessKeyId,
        #[SensitiveParameter] string $secretKey,
    ) {
        $this->signer = new S3Signer($accessKeyId, $secretKey, $region === '' ? 'us-east-1' : $region);
    }

    public function put(string $localPath, string $object, string $sha256, int $size): void
    {
        $request = $this->request('PUT', $object, $sha256)->withHeader('Content-Type', 'application/octet-stream');
        $response = $this->http->send($request, DestinationException::STEP_WRITE, $localPath);

        if ($response->getStatusCode() !== 200) {
            throw $this->failure(DestinationException::STEP_WRITE, $response);
        }
    }

    public function head(string $object): ?int
    {
        $response = $this->http->send($this->request('HEAD', $object), DestinationException::STEP_READ);

        return match ($response->getStatusCode()) {
            200 => (int) $response->getHeaderLine('Content-Length'),
            404 => null,
            default => throw $this->failure(DestinationException::STEP_READ, $response),
        };
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
        $status = $response->getStatusCode();

        if ($status !== 204 && $status !== 200 && $status !== 404) {
            throw $this->failure(DestinationException::STEP_DELETE, $response);
        }
    }

    protected function markerName(): string
    {
        return $this->prefix . self::MARKER;
    }

    /**
     * The URL an object lives at. `$object` is its full key.
     */
    public function url(string $object): string
    {
        $parts = parse_url(rtrim($this->endpoint, '/'));
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $base = rtrim((string) ($parts['path'] ?? ''), '/');
        // The object name is the whole key, prefix included, as recorded when
        // it was uploaded: a prefix changed later never moves old copies.
        $key = implode('/', array_map('rawurlencode', explode('/', $object)));

        return $this->pathStyle
            ? sprintf('%s://%s%s%s/%s/%s', $scheme, $host, $port, $base, rawurlencode($this->bucket), $key)
            : sprintf('%s://%s.%s%s%s/%s', $scheme, $this->bucket, $host, $port, $base, $key);
    }

    private function request(
        string $method,
        string $object,
        string $payload = S3Signer::EMPTY_PAYLOAD,
    ): RequestInterface {
        return $this->signer->sign(
            $this->requests->createRequest($method, $this->url($object)),
            $payload,
            $this->clock->now(),
        );
    }

    /**
     * The service's own error code (`AccessDenied`, `NoSuchBucket`,
     * `XAmzContentSHA256Mismatch`…) from its XML, which is all of the body
     * that is kept: a `SignatureDoesNotMatch` body echoes the request back.
     */
    private function failure(string $step, ResponseInterface $response): DestinationException
    {
        $status = $response->getStatusCode();
        $code = preg_match('#<Code>([^<]{1,64})</Code>#', (string) $response->getBody(), $m) === 1 ? $m[1] : '';

        $reason = match (true) {
            $status === 404 || $code === 'NoSuchKey' || $code === 'NoSuchBucket' => 'not_found',
            $status === 401 || $status === 403 => 'denied',
            in_array($code, ['XAmzContentSHA256Mismatch', 'BadDigest'], true) => 'checksum',
            default => 'refused',
        };

        return new DestinationException($step, $reason, sprintf(
            'The storage service answered %d%s.',
            $status,
            $code !== '' ? ' (' . HttpExchange::printable($code) . ')' : '',
        ));
    }
}
