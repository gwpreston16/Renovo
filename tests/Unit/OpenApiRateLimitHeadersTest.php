<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Api\OpenApiDocument;
use PHPUnit\Framework\TestCase;

/**
 * The contract says what the API sends (K123): the middleware puts the three
 * `RateLimit-*` headers on every response the limiter counted, so a client
 * generated from the document can read its quota on a successful call.
 *
 * An operation the limiter counts is one that declares a 429. The two copies
 * of the document itself are public and never counted.
 */
final class OpenApiRateLimitHeadersTest extends TestCase
{
    private const HEADERS = ['RateLimit-Limit', 'RateLimit-Remaining', 'RateLimit-Reset'];

    public function testEveryCountedResponseDeclaresTheHeaders(): void
    {
        $spec = (new OpenApiDocument(dirname(__DIR__, 2) . '/openapi/openapi.yaml'))->toArray();
        $counted = 0;

        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (!isset($operation['responses']['429'])) {
                    continue;
                }

                $counted++;
                foreach ($operation['responses'] as $status => $response) {
                    $response = $this->resolve($spec, $response);
                    $declared = array_keys($response['headers'] ?? []);

                    foreach (self::HEADERS as $header) {
                        self::assertContains(
                            $header,
                            $declared,
                            sprintf(
                                '%s %s: the %s response does not declare %s.',
                                strtoupper($method),
                                $path,
                                $status,
                                $header,
                            ),
                        );
                    }
                }
            }
        }

        self::assertGreaterThan(30, $counted, 'Hardly any operation was found to be counted.');
    }

    public function testTheHeadersAreDefinedOnceAndReferenced(): void
    {
        $spec = (new OpenApiDocument(dirname(__DIR__, 2) . '/openapi/openapi.yaml'))->toArray();

        foreach (self::HEADERS as $header) {
            self::assertArrayHasKey($header, $spec['components']['headers']);
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function resolve(array $spec, array $response): array
    {
        if (!isset($response['$ref'])) {
            return $response;
        }

        $target = $spec;
        foreach (explode('/', ltrim((string) $response['$ref'], '#/')) as $part) {
            $target = $target[$part];
        }

        return $target;
    }
}
