<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The first-run wizard asks for an account and nothing else.
 *
 * Everything the wizard used to collect — the household's name, the base
 * currency, the isolation mode, the rate provider — has a working default and
 * an editable home in settings. These tests hold that line from both ends: the
 * form does not offer the fields, and a submission that carries them anyway
 * (a stale bookmark, a script, somebody curling the endpoint) does not get to
 * set them.
 */
final class SetupWizardTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);
    }

    public function testTheFormAsksOnlyForTheAdministratorAccount(): void
    {
        $body = (string) $this->request('GET', '/setup')->getBody();

        self::assertStringContainsString('name="display_name"', $body);
        self::assertStringContainsString('name="password"', $body);

        self::assertStringNotContainsString('name="household_name"', $body);
        self::assertStringNotContainsString('name="instance_name"', $body);
        self::assertStringNotContainsString('name="base_currency"', $body);
        self::assertStringNotContainsString('name="isolation_mode"', $body);
        self::assertStringNotContainsString('name="rate_provider"', $body);
    }

    public function testTheFirstAccountGetsAHouseholdCalledHome(): void
    {
        $response = $this->submitValidSetup();

        self::assertSame(302, $response->getStatusCode());

        $users = new UserRepository($this->db);
        $user = $users->findByEmail('first@example.test');
        self::assertNotNull($user);
        self::assertTrue($user->isInstanceAdmin);

        $memberships = new MembershipRepository($this->db);
        $ownMemberships = $memberships->findAllForUser($user->id);
        self::assertCount(1, $ownMemberships);
        self::assertSame(Role::OwnerAdmin, $ownMemberships[0]->role);

        $household = (new HouseholdRepository($this->db))->findById($ownMemberships[0]->householdId);
        self::assertNotNull($household);
        self::assertSame('Home', $household->name);

        // Seeded once, with the household: the defaults, in the instance's
        // language, and not a second copy on any later request.
        $count = static fn ($db): int => (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->platform()->quoteIdentifier('payment_methods')
            . ' WHERE ' . $db->platform()->quoteIdentifier('household_id') . ' = :household',
            ['household' => $household->id],
        );
        self::assertSame(10, $count($this->db));

        $this->request('GET', '/setup');
        self::assertSame(10, $count($this->db));
    }

    public function testInstanceSettingsAreLeftAtTheirDefaults(): void
    {
        $this->submitValidSetup([
            // None of these are fields any more, so none of them may take.
            'household_name' => 'Somewhere else',
            'instance_name' => 'Renamed instance',
            'base_currency' => 'USD',
            'isolation_mode' => IsolationMode::Isolated->value,
            'rate_provider' => 'fixer',
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $settings = $container->get(InstanceSettingsService::class);

        self::assertSame('GBP', $settings->baseCurrency());
        self::assertSame(IsolationMode::Shared, $settings->isolationMode());
        self::assertSame('Renovo', $settings->instanceName());
        self::assertNull($settings->rateProvider());

        $memberships = new MembershipRepository($this->db);
        $user = (new UserRepository($this->db))->findByEmail('first@example.test');
        self::assertNotNull($user);
        $household = (new HouseholdRepository($this->db))
            ->findById($memberships->findAllForUser($user->id)[0]->householdId);
        self::assertNotNull($household);
        self::assertSame('Home', $household->name);
    }

    public function testTheWizardStillValidatesTheAccountFields(): void
    {
        $response = $this->request('POST', '/setup', [
            'display_name' => '',
            'email' => 'not-an-address',
            'password' => 'short',
            'password_confirm' => 'different',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, (new UserRepository($this->db))->countAll());
    }

    /**
     * @param array<string, string> $extra
     */
    private function submitValidSetup(array $extra = []): ResponseInterface
    {
        return $this->request('POST', '/setup', $extra + [
            'display_name' => 'First Admin',
            'email' => 'first@example.test',
            'password' => 'correct horse battery',
            'password_confirm' => 'correct horse battery',
        ]);
    }

    /**
     * @param array<string, string> $body
     *
     * @phpstan-impure
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
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
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
