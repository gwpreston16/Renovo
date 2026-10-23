<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\AppVersion;
use App\Application\Middleware\AuthenticationMiddleware;

/**
 * `GET /api/v1/instance`: the release, to any valid token, and to nobody
 * without one — not even a signed-in browser, whose cookie the API ignores.
 */
final class ApiInstanceTest extends ApiTestCase
{
    private string $versionFile;

    protected function setUp(): void
    {
        $this->versionFile = (string) tempnam(sys_get_temp_dir(), 'renovo-version-');
        file_put_contents($this->versionFile, "9.8.7\n");

        parent::setUp();
    }

    protected function tearDown(): void
    {
        @unlink($this->versionFile);
        parent::tearDown();
    }

    protected function containerOverrides(): array
    {
        return [AppVersion::class => new AppVersion($this->versionFile, 'a1b2c3d')];
    }

    public function testAnyValidTokenReadsTheVersionAndBuild(): void
    {
        foreach ([$this->viewerToken, $this->readOnlyToken, $this->ownerToken] as $token) {
            $response = $this->api('GET', '/api/v1/instance', $token);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(['data' => ['version' => '9.8.7', 'build' => 'a1b2c3d']], $this->decode($response));
        }
    }

    public function testNoTokenNoVersion(): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        foreach ([null, 'rnv_not-a-real-token'] as $token) {
            $response = $this->api('GET', '/api/v1/instance', $token);

            self::assertSame(401, $response->getStatusCode());
            self::assertStringNotContainsString('9.8.7', (string) $response->getBody());
        }
    }
}
