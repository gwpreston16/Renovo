<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backup;

use App\Service\Backup\Destination\S3Signer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Slim\Psr7\Factory\RequestFactory;

/**
 * The signer reproduces AWS's published Signature Version 4 examples: the
 * `get-vanilla` case of the SigV4 test suite, and the worked S3 examples
 * (GET Object with a range, PUT Object, GET Bucket lifecycle) from the S3 API
 * reference, with the example credentials those pages use.
 */
final class S3SignerTest extends TestCase
{
    private const S3_KEY = 'AKIAIOSFODNN7EXAMPLE';
    private const S3_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    public function testTheTestSuitesGetVanilla(): void
    {
        $signer = new S3Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service');
        $request = $this->request('GET', 'https://example.amazonaws.com/', [
            'Host' => 'example.amazonaws.com',
            'X-Amz-Date' => '20150830T123600Z',
        ]);

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request,'
            . ' SignedHeaders=host;x-amz-date,'
            . ' Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $signer->authorization($request, S3Signer::EMPTY_PAYLOAD, '20150830T123600Z'),
        );
    }

    public function testS3GetObjectWithARange(): void
    {
        $request = $this->request('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', [
            'Host' => 'examplebucket.s3.amazonaws.com',
            'Range' => 'bytes=0-9',
        ]);

        $signed = $this->s3()->sign($request, S3Signer::EMPTY_PAYLOAD, new DateTimeImmutable('2013-05-24T00:00:00Z'));

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,'
            . ' SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,'
            . ' Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $signed->getHeaderLine('Authorization'),
        );
        self::assertSame('20130524T000000Z', $signed->getHeaderLine('x-amz-date'));
        self::assertSame(S3Signer::EMPTY_PAYLOAD, $signed->getHeaderLine('x-amz-content-sha256'));
    }

    public function testS3PutObject(): void
    {
        $payload = hash('sha256', 'Welcome to Amazon S3.');
        $request = $this->request('PUT', 'https://examplebucket.s3.amazonaws.com/test$file.text', [
            'Host' => 'examplebucket.s3.amazonaws.com',
            'Date' => 'Fri, 24 May 2013 00:00:00 GMT',
            'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
        ]);

        $signed = $this->s3()->sign($request, $payload, new DateTimeImmutable('2013-05-24T00:00:00Z'));

        self::assertSame('44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', $payload);
        self::assertStringEndsWith(
            'SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class,'
            . ' Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
            $signed->getHeaderLine('Authorization'),
        );
    }

    public function testS3GetBucketLifecycleSignsTheQuery(): void
    {
        $request = $this->request('GET', 'https://examplebucket.s3.amazonaws.com/?lifecycle', [
            'Host' => 'examplebucket.s3.amazonaws.com',
        ]);

        $signed = $this->s3()->sign($request, S3Signer::EMPTY_PAYLOAD, new DateTimeImmutable('2013-05-24T00:00:00Z'));

        self::assertStringEndsWith(
            'Signature=fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543',
            $signed->getHeaderLine('Authorization'),
        );
    }

    public function testAHostIsAddedWithItsPortWhenTheRequestHasNone(): void
    {
        $request = (new RequestFactory())->createRequest('GET', 'http://minio.lan:9000/bucket/key')
            ->withoutHeader('Host');

        $signed = $this->s3()->sign($request, S3Signer::EMPTY_PAYLOAD, new DateTimeImmutable('2026-10-23T12:00:00Z'));

        self::assertSame('minio.lan:9000', $signed->getHeaderLine('Host'));
    }

    public function testPathsAndQueriesAreEncodedOnce(): void
    {
        self::assertSame('/', S3Signer::canonicalPath(''));
        self::assertSame('/bucket/a%20b/c%2Bd', S3Signer::canonicalPath('/bucket/a%20b/c+d'));
        self::assertSame('a=1&b=&b=2', S3Signer::canonicalQuery('b=2&a=1&b'));
    }

    private function s3(): S3Signer
    {
        return new S3Signer(self::S3_KEY, self::S3_SECRET, 'us-east-1');
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array $headers): RequestInterface
    {
        $request = (new RequestFactory())->createRequest($method, $url);
        foreach ($request->getHeaders() as $name => $_) {
            $request = $request->withoutHeader((string) $name);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
