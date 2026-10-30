<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Role;
use App\Domain\TokenScopes;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use App\Service\LogoStorage;
use App\Tests\Support\FakeUpload;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Phase 30: the tag and payment-method logo endpoints that caught the API up
 * with Settings.
 *
 * Each goes through the service the web form calls, so what is asserted here
 * is mostly that the API reaches the same rules — case-blind tag names, logos
 * judged by their contents — and that the scope still decides whose row an id
 * may name.
 */
final class ApiTaxonomyTest extends ApiTestCase
{
    private string $logoDirectory;

    protected function setUp(): void
    {
        $this->logoDirectory = sys_get_temp_dir() . '/renovo-api-logos-' . bin2hex(random_bytes(6));

        parent::setUp();
    }

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();
        foreach ((array) glob($this->logoDirectory . '/*') as $path) {
            @unlink((string) $path);
        }
        @rmdir($this->logoDirectory);

        parent::tearDown();
    }

    protected function containerOverrides(): array
    {
        return [LogoStorage::class => new LogoStorage($this->logoDirectory, 512 * 1024)];
    }

    public function testMoreThanTwentyFiveTagsOrALongTagAreRefusedWith422(): void
    {
        $base = [
            'name' => 'Streaming',
            'price_minor' => 1099,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ];

        $tooMany = $this->api('POST', '/api/v1/subscriptions', $this->editorToken, $base + [
            'tags' => array_map(static fn (int $n): string => 'tag' . $n, range(1, 26)),
        ]);
        self::assertSame(422, $tooMany->getStatusCode(), (string) $tooMany->getBody());
        self::assertStringContainsString('tags', (string) $tooMany->getBody());

        $tooLong = $this->api('POST', '/api/v1/subscriptions', $this->editorToken, $base + [
            'tags' => [str_repeat('x', 51)],
        ]);
        self::assertSame(422, $tooLong->getStatusCode(), (string) $tooLong->getBody());

        $limit = $this->api('POST', '/api/v1/subscriptions', $this->editorToken, $base + [
            'tags' => array_merge(
                array_map(static fn (int $n): string => 'tag' . $n, range(1, 24)),
                [str_repeat('x', 50)],
            ),
        ]);
        self::assertSame(201, $limit->getStatusCode(), (string) $limit->getBody());
    }

    public function testAListOrObjectForAColourOrNameIsRefusedAndClearsNothing(): void
    {
        $created = $this->api('POST', '/api/v1/categories', $this->ownerToken, [
            'name' => 'Fuel',
            'colour' => '#112233',
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (int) $this->decode($created)['data']['id'];

        // `true` as well as a list: read as a string it would be "1" (K171).
        foreach ([['colour', ['x']], ['colour', true], ['name', ['x']], ['name', true]] as [$field, $value]) {
            $put = $this->api('PUT', '/api/v1/categories/' . $id, $this->ownerToken, [
                $field => $value,
            ] + ['name' => 'Fuel', 'colour' => '#112233']);
            self::assertSame(422, $put->getStatusCode(), (string) $put->getBody());
            self::assertArrayHasKey($field, $this->decode($put)['error']['errors']);
        }

        foreach ([['x'], true] as $value) {
            $post = $this->api('POST', '/api/v1/categories', $this->ownerToken, ['name' => 'Other', 'colour' => $value]);
            self::assertSame(422, $post->getStatusCode(), (string) $post->getBody());
        }

        $kept = array_values(array_filter(
            $this->decode($this->api('GET', '/api/v1/categories', $this->ownerToken))['data'],
            fn (array $c): bool => (int) $c['id'] === $id,
        ));
        self::assertSame('#112233', $kept[0]['colour']);
        self::assertSame('Fuel', $kept[0]['name']);
    }

    public function testATagOrPaymentMethodRenameToAWrongTypeIsRefused(): void
    {
        // K171. Without the type check `true` would rename the row to "1".
        $tagId = (int) $this->decode($this->api('POST', '/api/v1/tags', $this->ownerToken, ['name' => 'Travel']))['data']['id'];
        $methodId = $this->createMethod();

        foreach ([true, ['x']] as $value) {
            foreach (['/api/v1/tags/' . $tagId, '/api/v1/payment-methods/' . $methodId] as $path) {
                $put = $this->api('PUT', $path, $this->ownerToken, ['name' => $value]);
                self::assertSame(422, $put->getStatusCode(), $path . ': ' . (string) $put->getBody());
                self::assertArrayHasKey('name', $this->decode($put)['error']['errors']);
            }
        }

        $names = static fn (array $rows, int $id): array => array_column(
            array_filter($rows, static fn (array $row): bool => (int) $row['id'] === $id),
            'name',
        );
        self::assertSame(
            ['Travel'],
            $names($this->decode($this->api('GET', '/api/v1/tags', $this->ownerToken))['data'], $tagId),
        );
        self::assertSame(
            ['Joint card'],
            $names($this->decode($this->api('GET', '/api/v1/payment-methods', $this->ownerToken))['data'], $methodId),
        );
    }

    public function testATagContainedInAnotherTagsNameIsNotADuplicate(): void
    {
        self::assertSame(
            201,
            $this->api('POST', '/api/v1/tags', $this->editorToken, ['name' => 'Apple TV'])->getStatusCode(),
        );
        self::assertSame(
            201,
            $this->api('POST', '/api/v1/tags', $this->editorToken, ['name' => 'TV'])->getStatusCode(),
        );
        self::assertSame(
            422,
            $this->api('POST', '/api/v1/tags', $this->editorToken, ['name' => 'tv'])->getStatusCode(),
        );

        $this->api('POST', '/api/v1/tags', $this->editorToken, ['name' => 'Café']);
        self::assertSame(
            422,
            $this->api('POST', '/api/v1/tags', $this->editorToken, ['name' => 'Cafe'])->getStatusCode(),
        );
    }

    public function testATagIsCreatedAndAnotherCaseOfItsNameIsRefused(): void
    {
        $response = $this->api('POST', '/api/v1/tags', $this->editorToken, ['name' => ' Work ']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $created = $this->decode($response)['data'];
        self::assertSame('Work', $created['name']);
        self::assertIsInt($created['id']);

        $listed = $this->decode($this->api('GET', '/api/v1/tags', $this->viewerToken))['data'];
        self::assertSame(['Work'], array_column($listed, 'name'));

        $duplicate = $this->api('POST', '/api/v1/tags', $this->editorToken, ['name' => 'WORK']);
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertArrayHasKey('name', $this->decode($duplicate)['error']['errors'] ?? []);

        self::assertSame(422, $this->api('POST', '/api/v1/tags', $this->editorToken, ['name' => ''])->getStatusCode());
    }

    public function testATagMadeHereIsTheOneASubscriptionNames(): void
    {
        $created = $this->api('POST', '/api/v1/tags', $this->ownerToken, ['name' => 'Music']);
        $tagId = $this->decode($created)['data']['id'];

        $this->createSubscription(['tags' => ['music']]);

        $tags = $this->decode($this->api('GET', '/api/v1/tags', $this->ownerToken))['data'];
        self::assertSame([['id' => $tagId, 'name' => 'Music']], $tags);
    }

    public function testRenamingATagRenamesItOnEverySubscriptionCarryingIt(): void
    {
        $subscriptionId = $this->createSubscription(['tags' => ['tv']]);
        $tagId = $this->decode($this->api('GET', '/api/v1/tags', $this->ownerToken))['data'][0]['id'];

        $response = $this->api('PUT', '/api/v1/tags/' . $tagId, $this->ownerToken, ['name' => 'Television']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['id' => $tagId, 'name' => 'Television'], $this->decode($response)['data']);

        $subscription = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $subscriptionId, $this->ownerToken));
        self::assertSame(['Television'], $subscription['data']['tags']);

        // Its own name in another case is not a clash with itself.
        $recased = $this->api('PUT', '/api/v1/tags/' . $tagId, $this->ownerToken, ['name' => 'TELEVISION']);
        self::assertSame(200, $recased->getStatusCode());
    }

    public function testAContributorMayNotCreateOrRenameTagsOrChangeALogo(): void
    {
        $users = new UserRepository($this->db);
        $id = $users->create('contributor@example.test', 'Contributor', 'hash', false, new DateTimeImmutable());
        (new MembershipRepository($this->db))->create($this->householdId, $id, Role::Contributor);
        $token = $this->container()->get(ApiTokenService::class)->issue(
            $users->findById($id),
            $this->householdId,
            'Contributor token',
            TokenScopes::everything(),
        );

        $created = $this->api('POST', '/api/v1/tags', $this->ownerToken, ['name' => 'Shared']);
        $tagId = $this->decode($created)['data']['id'];
        $methodId = $this->createMethod();

        self::assertSame(403, $this->api('POST', '/api/v1/tags', $token, ['name' => 'Mine'])->getStatusCode());
        self::assertSame(403, $this->api('PUT', '/api/v1/tags/' . $tagId, $token, ['name' => 'Mine'])->getStatusCode());
        self::assertSame(
            403,
            $this->upload('/api/v1/payment-methods/' . $methodId . '/logo', $token, FakeUpload::png())->getStatusCode(),
        );
        self::assertSame(
            403,
            $this->api('DELETE', '/api/v1/payment-methods/' . $methodId . '/logo', $token)->getStatusCode(),
        );
    }

    public function testAnotherHouseholdsTagAndPaymentMethodAreNotFound(): void
    {
        $tagId = $this->decode($this->api('POST', '/api/v1/tags', $this->ownerToken, ['name' => 'Ours']))['data']['id'];
        $methodId = $this->createMethod();

        $outsider = $this->outsider();

        self::assertSame(
            404,
            $this->api('PUT', '/api/v1/tags/' . $tagId, $outsider, ['name' => 'Taken'])->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->upload('/api/v1/payment-methods/' . $methodId . '/logo', $outsider, FakeUpload::png())
                ->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->api('DELETE', '/api/v1/payment-methods/' . $methodId . '/logo', $outsider)->getStatusCode(),
        );

        $ours = $this->decode($this->api('GET', '/api/v1/tags', $this->ownerToken))['data'];
        self::assertSame('Ours', $ours[0]['name']);
        self::assertSame([], (array) glob($this->logoDirectory . '/*'));
    }

    public function testAPaymentMethodLogoIsUploadedReplacedAndCleared(): void
    {
        $methodId = $this->createMethod();
        $path = '/api/v1/payment-methods/' . $methodId . '/logo';

        $first = $this->upload($path, $this->editorToken, FakeUpload::png('card.png'));
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $firstLogo = $this->decode($first)['data']['logo_path'];
        self::assertIsString($firstLogo);
        self::assertStringStartsWith('assets/logos/', $firstLogo);
        self::assertFileExists($this->logoDirectory . '/' . basename($firstLogo));

        // A replacement takes the earlier file with it.
        $second = $this->upload($path, $this->editorToken, FakeUpload::png('other.png'));
        self::assertSame(200, $second->getStatusCode());
        $secondLogo = $this->decode($second)['data']['logo_path'];
        self::assertNotSame($firstLogo, $secondLogo);
        self::assertFileDoesNotExist($this->logoDirectory . '/' . basename($firstLogo));

        self::assertSame(204, $this->api('DELETE', $path, $this->editorToken)->getStatusCode());
        self::assertFileDoesNotExist($this->logoDirectory . '/' . basename((string) $secondLogo));

        $listed = $this->decode($this->api('GET', '/api/v1/payment-methods', $this->editorToken))['data'];
        self::assertNull($listed[0]['logo_path']);
    }

    public function testALogoIsJudgedByItsContentsAndMustBeSent(): void
    {
        $methodId = $this->createMethod();
        $path = '/api/v1/payment-methods/' . $methodId . '/logo';

        $script = $this->upload($path, $this->ownerToken, FakeUpload::of("<?php echo 1;\n", 'logo.png'));
        self::assertSame(422, $script->getStatusCode());
        self::assertArrayHasKey('logo', $this->decode($script)['error']['errors'] ?? []);

        self::assertSame(400, $this->upload($path, $this->ownerToken, null)->getStatusCode());

        self::assertSame([], (array) glob($this->logoDirectory . '/*'));
    }

    private function createMethod(): int
    {
        $response = $this->api('POST', '/api/v1/payment-methods', $this->ownerToken, ['name' => 'Joint card']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->decode($response)['data']['id'];
    }

    /**
     * A multipart POST with a `logo` part, or with no file at all.
     */
    private function upload(string $path, string $token, ?UploadedFileInterface $logo): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=test')
            ->withParsedBody([]);

        if ($logo !== null) {
            $request = $request->withUploadedFiles(['logo' => $logo]);
        }

        return $this->app->handle($request);
    }

    /**
     * A write token for the Owner of a household of their own.
     */
    private function outsider(): string
    {
        $users = new UserRepository($this->db);
        $id = $users->create('outsider@example.test', 'Outsider', 'hash', false, new DateTimeImmutable());
        $householdId = (new HouseholdRepository($this->db))->create('Another household', $id);
        (new MembershipRepository($this->db))->create($householdId, $id, Role::OwnerAdmin);

        return $this->container()->get(ApiTokenService::class)->issue(
            $users->findById($id),
            $householdId,
            'Outsider token',
            TokenScopes::everything(),
        );
    }
}
