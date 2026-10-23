<?php

declare(strict_types=1);

namespace App\Service\Backup\Destination;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * AWS Signature Version 4, for S3-compatible storage (Phase 39).
 *
 * Written against AWS's published algorithm rather than taken from an SDK,
 * because an SDK brings its own HTTP stack and every request this application
 * makes goes through the shared, guarded client (non-negotiable 5). It is
 * checked against AWS's own examples in `S3SignerTest`.
 *
 *   1. The canonical request: method, path (each segment URI-encoded once,
 *      as S3 expects), sorted query, the signed headers lower-cased and
 *      sorted, and the payload's SHA-256.
 *   2. The string to sign: the algorithm, the time, the scope
 *      (`date/region/service/aws4_request`) and the canonical request's hash.
 *   3. The signing key: the secret HMAC'd through date, region, service and
 *      `aws4_request`, then the signature over the string to sign.
 *
 * The payload hash is the caller's: for an upload it is the backup's own
 * SHA-256, recorded when the backup verified, so the storage service refuses
 * a body that arrives different from the file that was checked.
 */
final class S3Signer
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    /** The hash of an empty body, for requests that send none. */
    public const EMPTY_PAYLOAD = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function __construct(
        private readonly string $accessKeyId,
        #[SensitiveParameter] private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {
    }

    /**
     * The request with `x-amz-date`, `x-amz-content-sha256` and
     * `Authorization` added. Every header already on it is signed.
     */
    public function sign(RequestInterface $request, string $payloadHash, DateTimeImmutable $now): RequestInterface
    {
        $time = $now->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $time->format('Ymd\THis\Z');

        if (!$request->hasHeader('Host')) {
            $uri = $request->getUri();
            $port = $uri->getPort();
            $request = $request->withHeader('Host', $uri->getHost() . ($port !== null ? ':' . $port : ''));
        }

        $request = $request
            ->withHeader('x-amz-date', $amzDate)
            ->withHeader('x-amz-content-sha256', $payloadHash);

        return $request->withHeader('Authorization', $this->authorization($request, $payloadHash, $amzDate));
    }

    /**
     * The `Authorization` value for a request whose headers — `x-amz-date`
     * among them — are already all on it.
     */
    public function authorization(RequestInterface $request, string $payloadHash, string $amzDate): string
    {
        $date = substr($amzDate, 0, 8);
        $scope = $date . '/' . $this->region . '/' . $this->service . '/aws4_request';
        [$canonical, $signedHeaders] = $this->canonicalRequest($request, $payloadHash);

        $stringToSign = self::ALGORITHM . "\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonical);

        return sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKeyId,
            $scope,
            $signedHeaders,
            hash_hmac('sha256', $stringToSign, $this->signingKey($date)),
        );
    }

    /**
     * @return array{string, string} The canonical request, and the signed
     *         header names as they appear in it.
     */
    public function canonicalRequest(RequestInterface $request, string $payloadHash): array
    {
        $uri = $request->getUri();

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $name = strtolower((string) $name);
            if ($name === 'authorization') {
                continue;
            }
            $headers[$name] = implode(',', array_map(
                static fn (string $value): string => (string) preg_replace('/\s+/', ' ', trim($value)),
                $values,
            ));
        }
        ksort($headers, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        return [
            implode("\n", [
                strtoupper($request->getMethod()),
                self::canonicalPath($uri->getPath()),
                self::canonicalQuery($uri->getQuery()),
                $canonicalHeaders,
                $signedHeaders,
                $payloadHash,
            ]),
            $signedHeaders,
        ];
    }

    /**
     * Each segment URI-encoded once, slashes kept. S3, unlike other AWS
     * services, does not normalise or double-encode the path.
     */
    public static function canonicalPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path),
        ));
    }

    /**
     * Name=value pairs, each side encoded, sorted by name then value.
     */
    public static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($name)), rawurlencode(rawurldecode($value))];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $pair): string => $pair[0] . '=' . $pair[1], $pairs));
    }

    private function signingKey(string $date): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }
}
