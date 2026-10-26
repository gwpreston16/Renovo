<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Role;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Service\AttachmentStorage;
use App\Tests\Support\FakeUpload;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Invoices and receipts over HTTP: upload, download and delete (K64).
 *
 * AttachmentTest covers the service. This covers what only the routes decide:
 * the download's headers, which are what stop a stored file from running as
 * this origin, and its 404 when the URL names a different subscription from
 * the one the attachment belongs to.
 */
final class AttachmentRoutesTest extends ApiTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/renovo-attachment-routes-' . bin2hex(random_bytes(6));

        parent::setUp();
    }

    protected function tearDown(): void
    {
        ApiChargesTest::removeDirectory($this->directory);

        parent::tearDown();
    }

    protected function containerOverrides(): array
    {
        return [AttachmentStorage::class => new AttachmentStorage($this->directory, 1024 * 1024)];
    }

    public function testAnUploadIsStoredAndTheDownloadCarriesEveryHeaderThatKeepsItInert(): void
    {
        $subscriptionId = $this->createSubscription();
        $this->signIn($this->ownerId);

        $upload = $this->webUpload($subscriptionId, FakeUpload::pdf('facture-août "final".pdf'));
        self::assertSame(302, $upload->getStatusCode());

        $attachmentId = $this->onlyAttachmentOf($subscriptionId);
        $download = $this->web('GET', '/subscriptions/' . $subscriptionId . '/attachments/' . $attachmentId);

        self::assertSame(200, $download->getStatusCode());
        self::assertStringStartsWith('%PDF-1.4', (string) $download->getBody());
        self::assertSame('application/pdf', $download->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('private, no-store', $download->getHeaderLine('Cache-Control'));
        self::assertSame("sandbox; default-src 'none'", $download->getHeaderLine('Content-Security-Policy'));
        // The quotes never reach the header (the stored name drops them), the
        // non-ASCII letter is replaced in the plain form, and the RFC 5987
        // form keeps it.
        self::assertSame(
            'attachment; filename="facture-ao__t final.pdf"; '
            . "filename*=UTF-8''facture-ao%C3%BBt%20final.pdf",
            $download->getHeaderLine('Content-Disposition'),
        );
    }

    public function testADownloadThroughAnotherSubscriptionsUrlIsNotFound(): void
    {
        $withFile = $this->createSubscription(['name' => 'With a file']);
        $other = $this->createSubscription(['name' => 'Another']);
        $this->signIn($this->ownerId);
        $this->webUpload($withFile, FakeUpload::pdf());
        $attachmentId = $this->onlyAttachmentOf($withFile);

        self::assertSame(
            404,
            $this->web('GET', '/subscriptions/' . $other . '/attachments/' . $attachmentId)->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->api(
                'GET',
                '/api/v1/subscriptions/' . $other . '/attachments/' . $attachmentId,
                $this->ownerToken,
            )->getStatusCode(),
        );
    }

    public function testTheApiDownloadCarriesTheSameHeaders(): void
    {
        $subscriptionId = $this->createSubscription();
        $this->signIn($this->ownerId);
        $this->webUpload($subscriptionId, FakeUpload::png('receipt.png'));
        $attachmentId = $this->onlyAttachmentOf($subscriptionId);

        $download = $this->api(
            'GET',
            '/api/v1/subscriptions/' . $subscriptionId . '/attachments/' . $attachmentId,
            $this->viewerToken,
        );

        self::assertSame(200, $download->getStatusCode());
        self::assertSame('image/png', $download->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("sandbox; default-src 'none'", $download->getHeaderLine('Content-Security-Policy'));
        self::assertStringStartsWith(
            'attachment; filename="receipt.png"',
            $download->getHeaderLine('Content-Disposition'),
        );
    }

    public function testDeleteRemovesTheAttachment(): void
    {
        $subscriptionId = $this->createSubscription();
        $this->signIn($this->ownerId);
        $this->webUpload($subscriptionId, FakeUpload::pdf());
        $attachmentId = $this->onlyAttachmentOf($subscriptionId);

        $delete = $this->web('POST', '/subscriptions/' . $subscriptionId . '/attachments/' . $attachmentId . '/delete');

        self::assertSame(302, $delete->getStatusCode());
        self::assertSame([], $this->attachmentsOf($subscriptionId));
        self::assertSame(
            404,
            $this->web('GET', '/subscriptions/' . $subscriptionId . '/attachments/' . $attachmentId)->getStatusCode(),
        );
    }

    public function testAViewerCanDownloadButNotUploadOrDelete(): void
    {
        $subscriptionId = $this->createSubscription();
        $this->signIn($this->ownerId);
        $this->webUpload($subscriptionId, FakeUpload::pdf());
        $attachmentId = $this->onlyAttachmentOf($subscriptionId);

        $this->signIn($this->viewerId);
        $path = '/subscriptions/' . $subscriptionId . '/attachments/' . $attachmentId;

        self::assertSame(200, $this->web('GET', $path)->getStatusCode());
        self::assertSame(403, $this->webUpload($subscriptionId, FakeUpload::pdf('second.pdf'))->getStatusCode());
        self::assertSame(403, $this->web('POST', $path . '/delete')->getStatusCode());
        self::assertCount(1, $this->attachmentsOf($subscriptionId));
    }

    public function testAContributorCannotTouchAnotherMembersAttachments(): void
    {
        $users = new UserRepository($this->db);
        $contributorId = $users->create(
            'contributor@example.test',
            'Contributor',
            'hash',
            false,
            new DateTimeImmutable(),
        );
        (new MembershipRepository($this->db))->create($this->householdId, $contributorId, Role::Contributor);

        $theirs = $this->createSubscription(['name' => 'Owner row']);
        $this->signIn($this->ownerId);
        $this->webUpload($theirs, FakeUpload::pdf());
        $attachmentId = $this->onlyAttachmentOf($theirs);
        $path = '/subscriptions/' . $theirs . '/attachments/' . $attachmentId;

        // SHARED: they read the household's invoice…
        $this->signIn($contributorId);
        self::assertSame(200, $this->web('GET', $path)->getStatusCode());

        // …and the fence refuses the write: an upload is sent back to the page
        // with a flash message and stores nothing, a delete is a 404, neither
        // a 403.
        self::assertSame(302, $this->webUpload($theirs, FakeUpload::pdf('theirs.pdf'))->getStatusCode());
        self::assertSame(404, $this->web('POST', $path . '/delete')->getStatusCode());
        self::assertSame([$attachmentId], $this->attachmentsOf($theirs));

        // On their own row the same routes work.
        $this->signIn($this->ownerId);
        $mine = $this->decode($this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
            'name' => 'Placeholder',
            'price_minor' => 100,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]))['data']['id'];
        $this->db->execute(
            'UPDATE subscriptions SET owner_user_id = :owner WHERE id = :id',
            ['owner' => $contributorId, 'id' => $mine],
        );

        $this->signIn($contributorId);
        self::assertSame(302, $this->webUpload((int) $mine, FakeUpload::pdf('mine.pdf'))->getStatusCode());
        $ownId = $this->onlyAttachmentOf((int) $mine);
        self::assertSame(200, $this->web('GET', '/subscriptions/' . $mine . '/attachments/' . $ownId)->getStatusCode());
        self::assertSame(
            302,
            $this->web('POST', '/subscriptions/' . $mine . '/attachments/' . $ownId . '/delete')->getStatusCode(),
        );
        self::assertSame([], $this->attachmentsOf((int) $mine));
    }

    private function signIn(int $userId): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function webUpload(int $subscriptionId, UploadedFileInterface $file): ResponseInterface
    {
        return $this->web('POST', '/subscriptions/' . $subscriptionId . '/attachments', ['file' => $file]);
    }

    /**
     * @param array<string, UploadedFileInterface> $files
     */
    private function web(string $method, string $path, array $files = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withUploadedFiles($files);

        if ($method === 'POST') {
            $request = $request
                ->withParsedBody([])
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }

    /**
     * @return list<int>
     */
    private function attachmentsOf(int $subscriptionId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id FROM attachments WHERE subscription_id = :id ORDER BY id',
            ['id' => $subscriptionId],
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function onlyAttachmentOf(int $subscriptionId): int
    {
        $ids = $this->attachmentsOf($subscriptionId);
        self::assertCount(1, $ids);

        return $ids[0];
    }
}
