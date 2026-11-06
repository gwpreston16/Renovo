<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\AttachmentStorage;
use App\Service\LogoStorage;
use App\Tests\Support\FakeUpload;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * K223: the size limits `docs/api.md` and the OpenAPI file state are the ones
 * the API enforces, at the defaults an operator gets (1 MB for a logo, 10 MB
 * for an attachment).
 *
 * The other upload tests shrink the limits so they can use small fixtures;
 * this one wires the shipped defaults. The constants below are the numbers
 * `UploadLimitDocsTest::testTheStatedLimitsAreTheShippedDefaults` pins to
 * `config/settings.php`, so a changed default fails there and the constants and
 * the documentation have to move together. Both sides of the line are tested:
 * one byte over is refused, and exactly the limit is not refused for its size.
 */
final class ApiUploadLimitsTest extends ApiTestCase
{
    /** The shipped `UPLOAD_MAX_LOGO_BYTES`. */
    private const LOGO_LIMIT = 1048576;

    /** The shipped `UPLOAD_MAX_ATTACHMENT_BYTES`. */
    private const ATTACHMENT_LIMIT = 10485760;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/renovo-api-limits-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/logos', 0777, true);
        mkdir($this->directory . '/attachments', 0777, true);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();
        foreach ((array) glob($this->directory . '/*/*') as $path) {
            @unlink((string) $path);
        }
        @rmdir($this->directory . '/logos');
        @rmdir($this->directory . '/attachments');
        @rmdir($this->directory);

        parent::tearDown();
    }

    protected function containerOverrides(): array
    {
        return [
            LogoStorage::class => new LogoStorage($this->directory . '/logos', self::LOGO_LIMIT),
            AttachmentStorage::class => new AttachmentStorage(
                $this->directory . '/attachments',
                self::ATTACHMENT_LIMIT,
            ),
        ];
    }

    public function testALogoOverOneMegabyteIsA422OnLogoAndStoresNothing(): void
    {
        $id = $this->createSubscription();

        $response = $this->upload(
            '/api/v1/subscriptions/' . $id . '/logo',
            'logo',
            FakeUpload::of($this->paddedPng(self::LOGO_LIMIT + 1), 'big.png'),
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertArrayHasKey('logo', $this->decode($response)['error']['errors'] ?? []);
        self::assertSame([], (array) glob($this->directory . '/logos/*'));
    }

    public function testAnAttachmentOverTenMegabytesIsA422OnFileAndStoresNothing(): void
    {
        $id = $this->createSubscription();
        $bytes = "%PDF-1.4\n" . str_repeat('0', self::ATTACHMENT_LIMIT + 1 - 9);

        $response = $this->upload(
            '/api/v1/subscriptions/' . $id . '/attachments',
            'file',
            FakeUpload::of($bytes, 'big.pdf'),
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertArrayHasKey('file', $this->decode($response)['error']['errors'] ?? []);
        self::assertSame([], (array) glob($this->directory . '/attachments/*'));
    }

    public function testALogoOfExactlyOneMegabyteIsNotRefusedForItsSize(): void
    {
        $id = $this->createSubscription();

        $response = $this->upload(
            '/api/v1/subscriptions/' . $id . '/logo',
            'logo',
            FakeUpload::of($this->paddedPng(self::LOGO_LIMIT), 'edge.png'),
        );

        // The limit is `>`, not `>=`. The padded bytes may or may not decode as
        // an image, but whatever is said of them, the size message must not be.
        self::assertStringNotContainsString('or smaller', (string) $response->getBody());
    }

    public function testAnAttachmentOfExactlyTenMegabytesIsAccepted(): void
    {
        $id = $this->createSubscription();
        $bytes = "%PDF-1.4\n" . str_repeat('0', self::ATTACHMENT_LIMIT - 9);
        self::assertSame(self::ATTACHMENT_LIMIT, strlen($bytes));

        $response = $this->upload(
            '/api/v1/subscriptions/' . $id . '/attachments',
            'file',
            FakeUpload::of($bytes, 'edge.pdf'),
        );

        self::assertStringNotContainsString('or smaller', (string) $response->getBody());
    }

    /**
     * A PNG with trailing padding, exactly $size bytes long.
     */
    private function paddedPng(int $size): string
    {
        $png = FakeUpload::pngBytes();

        return $png . str_repeat("\0", $size - strlen($png));
    }

    private function upload(string $path, string $part, UploadedFileInterface $file): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=test')
            ->withParsedBody([])
            ->withUploadedFiles([$part => $file]);

        return $this->app->handle($request);
    }
}
