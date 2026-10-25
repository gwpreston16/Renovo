<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\FakeUpload;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The payment-method screens through the real stack: the management page,
 * the select on the subscription form, and the badge on the list.
 */
final class PaymentMethodScreenTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private string $logoDirectory;
    private int $ownerId;
    private int $viewerId;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->logoDirectory = sys_get_temp_dir() . '/renovo-method-logos-' . bin2hex(random_bytes(6));

        /** @var array<string, mixed> $settings */
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        /** @var array<string, mixed> $uploads */
        $uploads = $settings['uploads'];
        $uploads['logo_directory'] = $this->logoDirectory;
        $settings['uploads'] = $uploads;

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            'settings' => $settings,
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $instance = $container->get(InstanceSettingsService::class);
        $instance->setIsolationMode(IsolationMode::Shared);
        $instance->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());
        $this->householdId = $households->create('Home', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);
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

    public function testAnEmptyListOffersTheDefaultsAndTheOfferSeedsThemOnce(): void
    {
        $this->signIn($this->ownerId);

        $page = $this->body($this->request('GET', '/settings'));
        self::assertStringContainsString('action="/payment-methods/defaults"', $page);

        self::assertSame(302, $this->request('POST', '/payment-methods/defaults')->getStatusCode());
        self::assertSame(302, $this->request('POST', '/payment-methods/defaults')->getStatusCode());
        self::assertCount(10, $this->methods());

        $page = $this->body($this->request('GET', '/settings'));
        self::assertStringNotContainsString('action="/payment-methods/defaults"', $page);
        // The generic icon, drawn by the server from the sprite.
        self::assertMatchesRegularExpression('~<use href="/build/sprite-[^"]+\.svg#payment-bank">~', $page);
    }

    public function testAMemberAddsAMethodWithALogoThenRenamesAndRemovesIt(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request(
            'POST',
            '/payment-methods',
            ['name' => 'Joint card', 'colour' => '#1069bb'],
            ['logo' => FakeUpload::png('card.png')],
        );
        self::assertSame(302, $response->getStatusCode(), $this->body($response));

        $method = $this->methods()[0];
        self::assertSame('Joint card', $method->name);
        self::assertSame('#1069bb', $method->colour);
        self::assertNotNull($method->logoPath);
        self::assertFileExists($this->logoDirectory . '/' . basename($method->logoPath));

        // "Automatic colour" clears it; no file keeps the logo.
        $this->request('POST', '/payment-methods/' . $method->id, [
            'name' => 'Joint account',
            'colour' => '#888888',
            'colour_auto' => '1',
        ]);
        $renamed = $this->methods()[0];

        // Listed as a chip that opens to edit it, above the form that adds one.
        $page = $this->body($this->request('GET', '/settings'));
        $chip = strpos($page, '<details class="setting-chip"');
        self::assertNotFalse($chip);
        self::assertGreaterThan($chip, strpos($page, 'action="/payment-methods" enctype'));
        self::assertSame('Joint account', $renamed->name);
        self::assertNull($renamed->colour);
        self::assertSame($method->logoPath, $renamed->logoPath);

        $this->request('POST', '/payment-methods/' . $method->id . '/logo/clear');
        self::assertNull($this->methods()[0]->logoPath);

        $this->request('POST', '/payment-methods/' . $method->id . '/delete');
        self::assertSame([], $this->methods());
    }

    public function testADisguisedLogoIsRefusedOnTheForm(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request(
            'POST',
            '/payment-methods',
            ['name' => 'Script'],
            ['logo' => FakeUpload::of("<?php echo 1;\n", 'logo.png')],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('field-error', $this->body($response));
        self::assertSame([], $this->methods());
    }

    public function testAViewerSeesTheListButNoForms(): void
    {
        (new PaymentMethodRepository($this->db))->create($this->ownerScope(), 'Joint card', null, 'payment-card', null);

        $this->signIn($this->viewerId);
        $response = $this->request('GET', '/settings');

        self::assertSame(200, $response->getStatusCode());
        $page = $this->body($response);
        self::assertStringContainsString('Joint card', $page);
        self::assertStringContainsString('<span class="setting-chip is-static">', $page);
        self::assertStringNotContainsString('<form method="post" action="/payment-methods', $page);

        self::assertSame(403, $this->request('POST', '/payment-methods', ['name' => 'Nope'])->getStatusCode());
    }

    public function testTheFormOffersTheMethodsAndTheListShowsTheChoice(): void
    {
        $card = (new PaymentMethodRepository($this->db))->create(
            $this->ownerScope(),
            'Joint card',
            null,
            'payment-card',
            null,
        );

        $this->signIn($this->ownerId);

        $form = $this->body($this->request('GET', '/subscriptions/new'));
        self::assertStringContainsString('name="payment_method_id"', $form);
        // The option's text is the name alone — a browser cannot draw a
        // picture inside an <option>, and dev-setup reads it back by name.
        self::assertMatchesRegularExpression(
            '#<option value="' . $card . '"[^>]*>Joint card</option>#',
            (string) preg_replace('/\s+/', ' ', $form),
        );

        $response = $this->request('POST', '/subscriptions', [
            'name' => 'Streaming',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'payment_method_id' => (string) $card,
            'is_active' => '1',
        ]);
        self::assertSame(302, $response->getStatusCode(), $this->body($response));

        $list = $this->body($this->request('GET', '/subscriptions'));
        self::assertStringContainsString('payment-badge', $list);
        self::assertStringContainsString('Joint card', $list);

        // The edit form comes back with the method chosen and its badge shown.
        $id = (int) $this->db->fetchValue(
            'SELECT MAX(' . $this->db->platform()->quoteIdentifier('id') . ') FROM '
            . $this->db->platform()->quoteIdentifier('subscriptions'),
        );
        $edit = $this->body($this->request('GET', '/subscriptions/' . $id . '/edit'));
        $edit = (string) preg_replace('/\s+/', ' ', $edit);
        self::assertMatchesRegularExpression('#<option value="' . $card . '"[^>]*selected[^>]*>Joint card#', $edit);
        self::assertMatchesRegularExpression('~<use href="/build/sprite-[^"]+\.svg#payment-card">~', $edit);
    }

    /**
     * @return list<\App\Domain\Entity\PaymentMethod>
     */
    private function methods(): array
    {
        return (new PaymentMethodRepository($this->db))->findAll($this->ownerScope());
    }

    private function ownerScope(): Scope
    {
        return Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, UploadedFileInterface> $files
     *
     * @phpstan-impure
     */
    private function request(string $method, string $path, array $body = [], array $files = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $container = $this->app->getContainer();
            self::assertNotNull($container);

            $request = $request
                ->withParsedBody($body)
                ->withUploadedFiles($files)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
