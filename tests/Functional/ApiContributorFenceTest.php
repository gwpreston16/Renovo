<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Role;
use App\Domain\TokenScopes;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use App\Service\AttachmentStorage;
use App\Service\LogoStorage;
use App\Tests\Support\FakeUpload;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The Contributor's write fence over the API (K20).
 *
 * In SHARED mode a Contributor reads the whole household and writes only the
 * rows they own. ScopingTest and ContributorRoleTest prove that at the
 * repository; this proves the endpoints reach it. Each refusal is a 404 (the
 * fence's answer, not 403), and the same call on the Contributor's own row
 * succeeds, so none of these can pass by refusing everything.
 */
final class ApiContributorFenceTest extends ApiTestCase
{
    private string $directory;
    private string $contributorToken;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/renovo-contributor-fence-' . bin2hex(random_bytes(6));

        parent::setUp();

        $users = new UserRepository($this->db);
        $id = $users->create('contributor@example.test', 'Contributor', 'hash', false, new DateTimeImmutable());
        (new MembershipRepository($this->db))->create($this->householdId, $id, Role::Contributor);
        $user = $users->findById($id);
        self::assertNotNull($user);

        $this->contributorToken = $this->container()->get(ApiTokenService::class)->issue(
            $user,
            $this->householdId,
            'Contributor token',
            TokenScopes::everything(),
        );
    }

    protected function tearDown(): void
    {
        ApiChargesTest::removeDirectory($this->directory);

        parent::tearDown();
    }

    protected function containerOverrides(): array
    {
        return [
            AttachmentStorage::class => new AttachmentStorage($this->directory . '/files', 1024 * 1024),
            LogoStorage::class => new LogoStorage($this->directory . '/logos', 512 * 1024),
        ];
    }

    public function testAContributorCannotWriteAnotherMembersSubscription(): void
    {
        $theirs = $this->createSubscription(['name' => 'Owner row']);
        $path = '/api/v1/subscriptions/' . $theirs;

        // Reading is open to the whole household.
        self::assertSame(200, $this->api('GET', $path, $this->contributorToken)->getStatusCode());

        $edit = $this->api('PUT', $path, $this->contributorToken, $this->payload('Hijacked'));
        self::assertSame(404, $edit->getStatusCode(), (string) $edit->getBody());
        self::assertSame(404, $this->api('POST', $path . '/cancel', $this->contributorToken)->getStatusCode());
        self::assertSame(404, $this->api('POST', $path . '/uncancel', $this->contributorToken)->getStatusCode());
        self::assertSame(
            404,
            $this->upload($path . '/logo', $this->contributorToken, 'logo', FakeUpload::png())->getStatusCode(),
        );
        self::assertSame(404, $this->api('DELETE', $path . '/logo', $this->contributorToken)->getStatusCode());
        self::assertSame(404, $this->api('DELETE', $path, $this->contributorToken)->getStatusCode());

        // Nothing changed: the row is still the Owner's, active, with no logo.
        $row = $this->decode($this->api('GET', $path, $this->ownerToken))['data'];
        self::assertSame('Owner row', $row['name']);
        self::assertNull($row['cancelled_at']);
        self::assertNull($row['logo_path']);
    }

    public function testAContributorGetsTheFencesNotFoundForAOneOffMovedOntoATakenDay(): void
    {
        $bought = (new DateTimeImmutable('-5 days'))->format('Y-m-d');
        $deposit = (new DateTimeImmutable('-8 days'))->format('Y-m-d');
        $theirs = $this->createSubscription([
            'name' => 'Desk',
            'price_minor' => 15000,
            'subscription_type' => 'one_off',
            'billing_cycle' => null,
            'next_payment_date' => null,
            'start_date' => $bought,
        ]);
        $path = '/api/v1/subscriptions/' . $theirs;
        $this->api('GET', $path . '/charges', $this->ownerToken);
        $this->api('POST', $path . '/charges', $this->ownerToken, ['date' => $deposit, 'amount_minor' => 1000]);

        // A day with a payment on it must not answer a Contributor with the
        // ledger's 422, which would tell them a charge stands there (K74).
        $row = $this->decode($this->api('GET', $path, $this->ownerToken))['data'];
        foreach ([$deposit, (new DateTimeImmutable('-20 days'))->format('Y-m-d')] as $date) {
            $response = $this->api('PUT', $path, $this->contributorToken, ['start_date' => $date] + $row);

            self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        }
    }

    /**
     * K31: the file is written before the write is refused, so a refused upload
     * must take it back out.
     */
    public function testAContributorsRefusedLogoUploadLeavesNoFileBehind(): void
    {
        $theirs = $this->createSubscription(['name' => 'Owner row']);

        $refused = $this->upload(
            '/api/v1/subscriptions/' . $theirs . '/logo',
            $this->contributorToken,
            'logo',
            FakeUpload::png(),
        );

        self::assertSame(404, $refused->getStatusCode(), (string) $refused->getBody());
        self::assertSame([], glob($this->directory . '/logos/*') ?: [], 'Nothing is left in logo storage.');
    }

    public function testAContributorCanWriteTheirOwnSubscription(): void
    {
        $mine = $this->createSubscription(['name' => 'Contributor row'], $this->contributorToken);
        $path = '/api/v1/subscriptions/' . $mine;

        $edit = $this->api('PUT', $path, $this->contributorToken, $this->payload('Renamed'));
        self::assertSame(200, $edit->getStatusCode(), (string) $edit->getBody());
        self::assertSame('Renamed', $this->decode($edit)['data']['name']);

        $cancel = $this->api('POST', $path . '/cancel', $this->contributorToken);
        self::assertSame(200, $cancel->getStatusCode(), (string) $cancel->getBody());
        self::assertSame(200, $this->api('POST', $path . '/uncancel', $this->contributorToken)->getStatusCode());

        $logo = $this->upload($path . '/logo', $this->contributorToken, 'logo', FakeUpload::png());
        self::assertSame(200, $logo->getStatusCode(), (string) $logo->getBody());
        self::assertNotNull($this->decode($logo)['data']['logo_path']);
        self::assertSame(204, $this->api('DELETE', $path . '/logo', $this->contributorToken)->getStatusCode());

        self::assertSame(204, $this->api('DELETE', $path, $this->contributorToken)->getStatusCode());
    }

    public function testAContributorCannotTouchAnotherMembersAttachmentsThroughTheApi(): void
    {
        $theirs = $this->createSubscription(['name' => 'Owner row']);
        $path = '/api/v1/subscriptions/' . $theirs . '/attachments';
        $upload = $this->upload($path, $this->ownerToken, 'file', FakeUpload::pdf('owner.pdf'));
        self::assertSame(201, $upload->getStatusCode(), (string) $upload->getBody());
        $attachmentId = (int) $this->decode($upload)['data']['id'];

        // They can see the invoice…
        self::assertSame(200, $this->api('GET', $path, $this->contributorToken)->getStatusCode());
        self::assertSame(200, $this->api('GET', $path . '/' . $attachmentId, $this->contributorToken)->getStatusCode());

        // …and cannot add to it or remove it.
        self::assertSame(
            404,
            $this->upload($path, $this->contributorToken, 'file', FakeUpload::pdf('theirs.pdf'))->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->api('DELETE', $path . '/' . $attachmentId, $this->contributorToken)->getStatusCode(),
        );
        self::assertCount(1, $this->attachmentsOf($theirs));

        // On their own row, the same calls work.
        $mine = $this->createSubscription(['name' => 'Contributor row'], $this->contributorToken);
        $minePath = '/api/v1/subscriptions/' . $mine . '/attachments';
        $own = $this->upload($minePath, $this->contributorToken, 'file', FakeUpload::pdf('mine.pdf'));
        self::assertSame(201, $own->getStatusCode(), (string) $own->getBody());
        $ownId = (int) $this->decode($own)['data']['id'];
        self::assertSame(204, $this->api('DELETE', $minePath . '/' . $ownId, $this->contributorToken)->getStatusCode());
        self::assertSame([], $this->attachmentsOf($mine));
    }

    public function testAMissingOrHiddenSubscriptionIsA404(): void
    {
        $missing = '/api/v1/subscriptions/999999/attachments';

        self::assertSame(404, $this->api('GET', $missing, $this->ownerToken)->getStatusCode());
        self::assertSame(
            404,
            $this->upload($missing, $this->ownerToken, 'file', FakeUpload::pdf())->getStatusCode(),
        );

        // One that exists, in a household the token cannot see, is the same answer.
        $id = $this->createSubscription(['name' => 'Ours']);
        $outsider = $this->outsiderToken();
        $path = '/api/v1/subscriptions/' . $id . '/attachments';
        self::assertSame(404, $this->api('GET', $path, $outsider)->getStatusCode());
        self::assertSame(404, $this->upload($path, $outsider, 'file', FakeUpload::pdf())->getStatusCode());
        self::assertSame([], $this->attachmentsOf($id));

        // The visible one still lists, empty.
        $listed = $this->api('GET', $path, $this->ownerToken);
        self::assertSame(200, $listed->getStatusCode());
        self::assertSame([], $this->decode($listed)['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $name): array
    {
        return [
            'name' => $name,
            'price_minor' => 1099,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ];
    }

    private function upload(string $path, string $token, string $part, UploadedFileInterface $file): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=test')
            ->withParsedBody([])
            ->withUploadedFiles([$part => $file]);

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

    /**
     * A write token for the Owner of a household of their own.
     */
    private function outsiderToken(): string
    {
        $users = new UserRepository($this->db);
        $id = $users->create('outsider@example.test', 'Outsider', 'hash', false, new DateTimeImmutable());
        $householdId = (new \App\Repository\HouseholdRepository($this->db))->create('Another household', $id);
        (new MembershipRepository($this->db))->create($householdId, $id, Role::OwnerAdmin);
        $user = $users->findById($id);
        self::assertNotNull($user);

        return $this->container()->get(ApiTokenService::class)->issue(
            $user,
            $householdId,
            'Outsider token',
            TokenScopes::everything(),
        );
    }
}
