<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Role;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Domain\IsolationMode;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Repository\LogoUsageRepository;
use App\Repository\PaymentMethodRepository;
use App\Service\LogoStorage;
use App\Service\SubscriptionService;
use App\Tests\Support\FakeUpload;
use Psr\Http\Message\UploadedFileInterface;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A subscription's logo is only ever a file uploaded for it (K1).
 *
 * The edit form used to save any `logo_path` it was posted when no file came
 * with it, so a member could point every household page at a third-party
 * image, or copy a co-member's logo path onto their own row and delete that
 * row to delete the co-member's file.
 */
final class LogoPathTest extends ApiTestCase
{
    private const MEMBERS_LOGO = 'assets/logos/0123456789abcdef0123456789abcdef.png';

    private int $contributorId;
    private string $logoDirectory;

    protected function setUp(): void
    {
        $this->logoDirectory = sys_get_temp_dir() . '/renovo-logo-path-' . bin2hex(random_bytes(6));

        parent::setUp();

        $this->contributorId = (new UserRepository($this->db))
            ->create('contributor@example.test', 'Contributor', 'hash', false, new DateTimeImmutable());
        (new MembershipRepository($this->db))->create($this->householdId, $this->contributorId, Role::Contributor);
    }

    protected function tearDown(): void
    {
        ApiChargesTest::removeDirectory($this->logoDirectory);

        parent::tearDown();
    }

    protected function containerOverrides(): array
    {
        return [LogoStorage::class => new LogoStorage(
            $this->logoDirectory,
            512 * 1024,
            new LogoUsageRepository($this->db),
        )];
    }

    /**
     * The other half of K1: refusing posted paths must not refuse the real
     * thing. A logo uploaded with the form is saved on create and on edit,
     * and an edit's upload replaces the stored one.
     */
    public function testALogoUploadedWithTheFormIsStillSaved(): void
    {
        $this->signIn($this->editorId);

        $create = $this->web('POST', '/subscriptions', $this->form([]), ['logo' => FakeUpload::png('one.png')]);
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());
        $id = (int) $this->db->fetchValue('SELECT MAX(id) FROM subscriptions');
        $first = (string) $this->logoOf($id);
        self::assertMatchesRegularExpression('~^assets/logos/[0-9a-f]{32}\.png$~', $first);

        $edit = $this->web('POST', '/subscriptions/' . $id, $this->form([
            'logo_path' => '/attacker.example/p.png',
        ]), ['logo' => FakeUpload::png('two.png')]);
        self::assertSame(302, $edit->getStatusCode(), (string) $edit->getBody());
        $second = (string) $this->logoOf($id);
        self::assertMatchesRegularExpression('~^assets/logos/[0-9a-f]{32}\.png$~', $second);
        self::assertNotSame($first, $second);
    }

    public function testEveryPathLogoStorageWritesCountsAsStored(): void
    {
        $storage = new LogoStorage($this->logoDirectory, 512 * 1024);

        self::assertTrue(LogoStorage::isStoredPath($storage->store(FakeUpload::png())));

        $source = (string) tempnam(sys_get_temp_dir(), 'renovo-logo');
        file_put_contents($source, FakeUpload::pngBytes());
        self::assertTrue(LogoStorage::isStoredPath($storage->storeFile($source)));
        unlink($source);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function postedPaths(): array
    {
        return [
            'a third-party image' => ['/attacker.example/p.png'],
            'a protocol-relative URL' => ['//attacker.example/p.png'],
            'an absolute URL' => ['https://attacker.example/p.png'],
            "a co-member's stored logo" => [self::MEMBERS_LOGO],
        ];
    }

    /**
     * @dataProvider postedPaths
     */
    public function testAnEditorsPostedPathIsNotSaved(string $posted): void
    {
        $subscriptionId = $this->createSubscription();

        $this->signIn($this->editorId);
        $response = $this->postEdit($subscriptionId, $posted);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->logoOf($subscriptionId));
    }

    /**
     * @dataProvider postedPaths
     */
    public function testAContributorsPostedPathIsNotSavedOnTheirOwnRow(string $posted): void
    {
        $this->signIn($this->contributorId);
        $create = $this->web('POST', '/subscriptions', $this->form(['owner_user_id' => (string) $this->contributorId]));
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());
        $subscriptionId = (int) $this->db->fetchValue('SELECT MAX(id) FROM subscriptions');

        $response = $this->postEdit($subscriptionId, $posted, $this->contributorId);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->logoOf($subscriptionId));
    }

    public function testAPathPostedOnCreateIsNotSavedEither(): void
    {
        $this->signIn($this->editorId);

        $response = $this->web('POST', '/subscriptions', $this->form(['logo_path' => self::MEMBERS_LOGO]));

        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM subscriptions'));
        self::assertNull($this->db->fetchValue('SELECT logo_path FROM subscriptions'));
    }

    public function testTheStoredLogoSurvivesAnEditThatPostsAnotherPath(): void
    {
        $subscriptionId = $this->createSubscription();
        $this->db->execute(
            'UPDATE subscriptions SET logo_path = :path WHERE id = :id',
            ['path' => self::MEMBERS_LOGO, 'id' => $subscriptionId],
        );

        $this->signIn($this->editorId);
        $response = $this->postEdit($subscriptionId, '/attacker.example/p.png');

        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(self::MEMBERS_LOGO, $this->logoOf($subscriptionId));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function foreignPaths(): array
    {
        return array_diff_key(self::postedPaths(), ["a co-member's stored logo" => true]);
    }

    /**
     * The service holds the line too, for any caller that hands it input
     * without going through the form controller. (A real stored path is the
     * one thing it must accept — it is what an upload hands it — so keeping a
     * co-member's out is the controller's job, tested above.)
     *
     * @dataProvider foreignPaths
     */
    public function testTheServiceSavesNoPathLogoStorageCouldNotHaveWritten(string $posted): void
    {
        $service = $this->container()->get(SubscriptionService::class);
        $scope = Scope::forMember($this->editorId, false, $this->householdId, Role::Editor, IsolationMode::Shared);
        $input = [
            'name' => 'Policy',
            'price' => '10.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'logo_path' => $posted,
        ];

        $id = $service->create($scope, $input);
        self::assertNull($this->logoOf($id));

        $this->db->execute(
            'UPDATE subscriptions SET logo_path = :path WHERE id = :id',
            ['path' => self::MEMBERS_LOGO, 'id' => $id],
        );
        $service->update($scope, $id, $input);
        self::assertSame(self::MEMBERS_LOGO, $this->logoOf($id));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function storedPathShapes(): array
    {
        $hex = '0123456789abcdef0123456789abcdef';

        return [
            'a png it wrote' => ['assets/logos/' . $hex . '.png', true],
            'a webp it wrote' => ['assets/logos/' . $hex . '.webp', true],
            'climbing out' => ['assets/logos/../' . $hex . '.png', false],
            'a subdirectory' => ['assets/logos/sub/' . $hex . '.png', false],
            'upper-case hex' => ['assets/logos/' . strtoupper($hex) . '.png', false],
            'a trailing newline' => ['assets/logos/' . $hex . ".png
", false],
            'an svg' => ['assets/logos/' . $hex . '.svg', false],
            'a URL' => ['https://attacker.example/' . $hex . '.png', false],
        ];
    }

    /**
     * @dataProvider storedPathShapes
     */
    public function testOnlyAPathLogoStorageWritesCountsAsStored(string $path, bool $stored): void
    {
        self::assertSame($stored, LogoStorage::isStoredPath($path));
    }

    public function testDeleteLeavesAFileItWasNotGivenAStoredPathFor(): void
    {
        $directory = sys_get_temp_dir() . '/renovo-logos-' . bin2hex(random_bytes(6));
        mkdir($directory . '/sub', 0o775, true);
        $file = $directory . '/sub/0123456789abcdef0123456789abcdef.png';
        file_put_contents($file, 'x');

        (new LogoStorage($directory, 1024))->delete('assets/logos/sub/0123456789abcdef0123456789abcdef.png');

        self::assertFileExists($file);
        unlink($file);
        rmdir($directory . '/sub');
        rmdir($directory);
    }

    /**
     * K31: a create or edit that fails validation has already stored its
     * upload, and no row will ever name it.
     */
    public function testAWebCreateThatFailsValidationLeavesNoLogoBehind(): void
    {
        $this->signIn($this->editorId);

        $response = $this->web('POST', '/subscriptions', $this->form(['name' => '']), ['logo' => FakeUpload::png()]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->storedFiles());
    }

    public function testAWebEditThatFailsValidationLeavesNoLogoBehind(): void
    {
        $subscriptionId = $this->createSubscription();
        $this->signIn($this->editorId);

        $response = $this->web(
            'POST',
            '/subscriptions/' . $subscriptionId,
            $this->form(['name' => '']),
            ['logo' => FakeUpload::png()],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->storedFiles());
    }

    public function testAWebEditRefusedByTheFenceLeavesNoLogoBehind(): void
    {
        $subscriptionId = $this->createSubscription();
        $this->signIn($this->contributorId);

        $response = $this->web(
            'POST',
            '/subscriptions/' . $subscriptionId,
            $this->form(['owner_user_id' => (string) $this->contributorId]),
            ['logo' => FakeUpload::png()],
        );

        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([], $this->storedFiles());
    }

    /**
     * K120: a row saved before K1's fix may name a co-member's file. Removing
     * that row, or its logo, must not take the co-member's file with it.
     */
    public function testDeletingARowKeepsAFileAnotherRowUses(): void
    {
        [$theirs, $file] = $this->subscriptionWithAStoredLogo();
        $mine = $this->createSubscription();
        $this->nameLogo($mine, $this->logoOf($theirs));

        $this->signIn($this->editorId);
        $response = $this->web('POST', '/subscriptions/' . $mine . '/delete', []);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        self::assertFileExists($file, "The co-member's file is kept.");
        self::assertSame(basename($file), basename((string) $this->logoOf($theirs)));
    }

    public function testDeletingARowRemovesItsOwnLogo(): void
    {
        [$mine, $file] = $this->subscriptionWithAStoredLogo();

        $this->signIn($this->editorId);
        $response = $this->web('POST', '/subscriptions/' . $mine . '/delete', []);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        self::assertFileDoesNotExist($file);
    }

    public function testRemovingAndReplacingALogoKeepsAFileAnotherRowUses(): void
    {
        [$theirs, $file] = $this->subscriptionWithAStoredLogo();
        $mine = $this->createSubscription();
        $this->nameLogo($mine, $this->logoOf($theirs));

        $replaced = $this->uploadLogo($mine);
        self::assertSame(200, $replaced->getStatusCode(), (string) $replaced->getBody());
        self::assertFileExists($file, 'Replacing keeps the file the other row uses.');

        $this->nameLogo($mine, $this->logoOf($theirs));
        $removed = $this->api('DELETE', '/api/v1/subscriptions/' . $mine . '/logo', $this->ownerToken);
        self::assertSame(204, $removed->getStatusCode());
        self::assertFileExists($file, 'Removing keeps it too.');
        self::assertNotNull($this->logoOf($theirs));
    }

    public function testAFileAPaymentMethodUsesIsKeptWhenASubscriptionLetsGo(): void
    {
        [$mine, $file] = $this->subscriptionWithAStoredLogo();
        $scope = Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        (new PaymentMethodRepository($this->db))->create($scope, 'Card', null, null, $this->logoOf($mine));

        $removed = $this->api('DELETE', '/api/v1/subscriptions/' . $mine . '/logo', $this->ownerToken);

        self::assertSame(204, $removed->getStatusCode());
        self::assertFileExists($file, 'The payment method still shows it.');
    }

    /**
     * @return array{0: int, 1: string} The subscription and its logo file.
     */
    private function subscriptionWithAStoredLogo(): array
    {
        $id = $this->createSubscription();
        $uploaded = $this->uploadLogo($id);
        self::assertSame(200, $uploaded->getStatusCode(), (string) $uploaded->getBody());
        $file = $this->logoDirectory . '/' . basename((string) $this->logoOf($id));
        self::assertFileExists($file);

        return [$id, $file];
    }

    private function uploadLogo(int $id): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                'http://localhost/api/v1/subscriptions/' . $id . '/logo',
                ['REMOTE_ADDR' => '127.0.0.1'],
            )
            ->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->withUploadedFiles(['logo' => FakeUpload::png()]);

        return $this->app->handle($request);
    }

    private function nameLogo(int $id, ?string $path): void
    {
        $this->db->execute('UPDATE subscriptions SET logo_path = :path WHERE id = :id', ['path' => $path, 'id' => $id]);
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        return is_dir($this->logoDirectory)
            ? array_values(array_diff((array) scandir($this->logoDirectory), ['.', '..']))
            : [];
    }

    private function postEdit(int $subscriptionId, string $logoPath, ?int $ownerId = null): ResponseInterface
    {
        return $this->web('POST', '/subscriptions/' . $subscriptionId, $this->form([
            'logo_path' => $logoPath,
            'owner_user_id' => (string) ($ownerId ?? $this->ownerId),
        ]));
    }

    private function logoOf(int $subscriptionId): ?string
    {
        $path = $this->db->fetchValue('SELECT logo_path FROM subscriptions WHERE id = :id', ['id' => $subscriptionId]);

        return $path === null ? null : (string) $path;
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function form(array $overrides): array
    {
        return $overrides + [
            'name' => 'Streaming',
            'price' => '10.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => '1',
            'visibility' => 'household',
            'owner_user_id' => (string) $this->ownerId,
        ];
    }

    private function signIn(int $userId): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, UploadedFileInterface> $files
     */
    private function web(string $method, string $path, array $body, array $files = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody($body)
            ->withUploadedFiles($files)
            ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token());

        return $this->app->handle($request);
    }
}
