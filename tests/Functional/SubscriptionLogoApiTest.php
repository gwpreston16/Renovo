<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeUpload;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * `POST /api/v1/subscriptions/{id}/logo`: what a logo part may and may not do.
 */
final class SubscriptionLogoApiTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        FakeUpload::cleanUp();
        parent::tearDown();
    }

    public function testAPartWithNoFileIsRefusedAndKeepsTheLogo(): void
    {
        $id = $this->createSubscription(['name' => 'Streaming']);
        $uploaded = $this->upload($id, FakeUpload::png());
        self::assertSame(200, $uploaded->getStatusCode(), (string) $uploaded->getBody());
        $stored = $this->logoPathInDatabase($id);
        self::assertNotNull($stored);

        $empty = $this->upload($id, new UploadedFile('', '', '', 0, UPLOAD_ERR_NO_FILE));

        self::assertSame(422, $empty->getStatusCode(), (string) $empty->getBody());
        self::assertArrayHasKey('logo', $this->decode($empty)['error']['errors'] ?? []);
        self::assertSame($stored, $this->logoPathInDatabase($id));
    }

    private function logoPathInDatabase(int $id): ?string
    {
        $row = $this->db->fetchOne('SELECT logo_path FROM subscriptions WHERE id = :id', ['id' => $id]);

        return $row === null || $row['logo_path'] === null ? null : (string) $row['logo_path'];
    }

    private function upload(int $id, UploadedFile $file): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/v1/subscriptions/' . $id . '/logo', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->withUploadedFiles(['logo' => $file]);

        return $this->app->handle($request);
    }
}
