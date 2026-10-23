<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Entity\NotificationPreferences;
use App\Domain\IsolationMode;
use App\Domain\MembershipStatus;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\NotificationChannelRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Support\SystemClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeZone;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;
use App\Tests\Support\TestKeys;

/**
 * The wizard's three steps (Phase 29): the owner account, the household, and
 * reminders — and the page it ends on.
 *
 * Step one is SetupWizardTest's subject. These hold the two steps after it:
 * that every value they collect is persisted by the service that owns it,
 * that a step left alone leaves the defaults, that an invitation is checked
 * where it can be corrected and sent only when setup finishes, and that the
 * steps close once the wizard is done.
 */
final class SetupWizardStepsTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private RecordingMailer $mailer;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->mailer = new RecordingMailer();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => $this->mailer,
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $this->container = $container;
    }

    public function testStepOneLeadsToTheHouseholdStep(): void
    {
        $response = $this->createOwner();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/setup/household', $response->getHeaderLine('Location'));

        $html = $this->body('GET', '/setup/household');
        self::assertStringContainsString('Step 2 of 3', $html);
        self::assertStringContainsString('value="Home"', $html);
        // The full list, the common few first.
        self::assertStringContainsString('<option value="JPY"', $html);
        self::assertLessThan(strpos($html, '<option value="JPY"'), strpos($html, '<option value="EUR"'));
    }

    public function testTheHouseholdStepPersistsEveryValue(): void
    {
        $this->createOwner();

        $response = $this->request('POST', '/setup/household', [
            'household_name' => 'Jenkins household',
            'base_currency' => 'EUR',
            'isolation_mode' => IsolationMode::Isolated->value,
            'rate_provider' => 'fixer',
            'rate_provider_key' => 'a-fixer-key',
            'invites' => '',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/setup/notifications', $response->getHeaderLine('Location'));

        $settings = $this->container->get(InstanceSettingsService::class);
        self::assertSame('EUR', $settings->baseCurrency());
        self::assertSame(IsolationMode::Isolated, $settings->isolationMode());
        self::assertSame('fixer', $settings->rateProvider());
        self::assertSame('a-fixer-key', $settings->storedRateProviderKey());
        self::assertSame('Jenkins household', $this->householdName());
    }

    public function testABadInvitationIsReportedOnTheHouseholdStepAndNothingIsSaved(): void
    {
        $this->createOwner();

        $response = $this->request('POST', '/setup/household', [
            'household_name' => 'Jenkins household',
            'base_currency' => 'EUR',
            'invites' => 'tom@example.test, not-an-address',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('not-an-address', (string) $response->getBody());
        self::assertSame('Home', $this->householdName());
        self::assertSame('GBP', $this->container->get(InstanceSettingsService::class)->baseCurrency());
    }

    public function testInvitationsAreSentWhenSetupFinishesAndNotBefore(): void
    {
        $this->createOwner();

        $this->request('POST', '/setup/household', [
            'household_name' => 'Jenkins household',
            'base_currency' => 'GBP',
            'invites' => 'tom@example.test, ellie@example.test',
        ]);

        self::assertCount(0, $this->mailer->messages, 'Nobody is invited until setup finishes.');
        self::assertStringContainsString(
            'tom@example.test, ellie@example.test',
            $this->body('GET', '/setup/household'),
        );

        $this->request('POST', '/setup/notifications/finish', []);

        self::assertCount(2, $this->mailer->messages);

        $tom = (new UserRepository($this->db))->findByEmail('tom@example.test');
        self::assertNotNull($tom);
        $membership = (new MembershipRepository($this->db))->findAllForUser($tom->id)[0];
        self::assertSame(\App\Domain\Role::Contributor, $membership->role);
        self::assertSame(MembershipStatus::Pending, $membership->status);

        $done = $this->body('GET', '/setup/done');
        self::assertStringContainsString('Jenkins household is ready', $done);
        self::assertStringContainsString('Invitations went to tom@example.test, ellie@example.test.', $done);
    }

    public function testTheRemindersStepPersistsEmailBudgetAlertsAndLeadTimes(): void
    {
        $this->createOwner();
        $owner = $this->owner();

        $response = $this->request('POST', '/setup/notifications/finish', [
            'reminders' => '1',
            'email' => '1',
            'budget_alerts' => '0',
            'lead_days' => ['', '3', '7'],
        ]);

        self::assertSame('/setup/done', $response->getHeaderLine('Location'));

        $preferences = $this->preferences($owner);
        self::assertSame([7, 3], $preferences->leadDays);
        self::assertFalse($preferences->budgetAlerts);

        $channels = $this->channels($owner);
        self::assertCount(1, $channels);
        self::assertSame('email', $channels[0]->type);
        self::assertTrue($channels[0]->isActive);

        self::assertTrue($this->container->get(InstanceSettingsService::class)->isNotificationSetupComplete());
    }

    public function testAChannelAddedOnTheRemindersStepIsSaved(): void
    {
        $this->createOwner();

        $this->request('POST', '/setup/notifications/channels', [
            'channel_type' => 'ntfy',
            'label' => 'Phone',
            'server' => 'https://ntfy.example.com',
            'topic' => 'renovo',
        ]);

        $channels = $this->channels($this->owner());
        self::assertCount(1, $channels);
        self::assertSame('ntfy', $channels[0]->type);
        self::assertStringContainsString('Phone', $this->body('GET', '/setup/notifications'));
    }

    public function testSkippingTheOptionalStepsLeavesTheDefaults(): void
    {
        $this->createOwner();
        $owner = $this->owner();

        // Straight to Finish, as a post that is not the step's own form.
        $this->request('POST', '/setup/notifications/finish', []);

        $settings = $this->container->get(InstanceSettingsService::class);
        self::assertSame('GBP', $settings->baseCurrency());
        self::assertSame(IsolationMode::Shared, $settings->isolationMode());
        self::assertNull($settings->rateProvider());
        self::assertSame('Home', $this->householdName());

        self::assertSame(NotificationPreferences::defaults($owner)->leadDays, $this->preferences($owner)->leadDays);
        self::assertSame([], $this->channels($owner));
    }

    public function testTheStepsCloseOnceTheWizardIsFinished(): void
    {
        $this->createOwner();
        $this->request('POST', '/setup/notifications/finish', []);

        foreach (['/setup/household', '/setup/notifications'] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(302, $response->getStatusCode(), $path);
            self::assertSame('/', $response->getHeaderLine('Location'), $path);
        }

        // The done page is shown once.
        self::assertSame(200, $this->request('GET', '/setup/done')->getStatusCode());
        self::assertSame(302, $this->request('GET', '/setup/done')->getStatusCode());

        // And step one stays shut, as it always has.
        self::assertSame(302, $this->request('GET', '/setup')->getStatusCode());
    }

    public function testTheLaterStepsAreForTheSignedInAdministratorOnly(): void
    {
        $this->createOwner();
        $this->session->clear();

        $response = $this->request('GET', '/setup/household');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/login', $response->getHeaderLine('Location'));

        $response = $this->request('POST', '/setup/household', ['household_name' => 'Taken over']);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('Home', $this->householdName());
    }

    public function testAnAdministratorWhoCannotManageTheHouseholdCannotRenameIt(): void
    {
        $this->createOwner();
        $owner = $this->owner();

        // Demoted in their own household after the account was made.
        $householdId = (new MembershipRepository($this->db))->findAllForUser($owner)[0]->householdId;
        (new MembershipRepository($this->db))->updateRole($householdId, $owner, \App\Domain\Role::Viewer);

        $response = $this->request('POST', '/setup/household', [
            'household_name' => 'Taken over',
            'base_currency' => 'EUR',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Home', $this->householdName());
        self::assertSame('GBP', $this->container->get(InstanceSettingsService::class)->baseCurrency());
    }

    public function testTheGuardOpensOnlyTheNamedSteps(): void
    {
        $this->createOwner();

        // A /setup path that is not one of the steps is still closed.
        $response = $this->request('GET', '/setup/anything-else');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    private function createOwner(): ResponseInterface
    {
        return $this->request('POST', '/setup', [
            'display_name' => 'First Admin',
            'email' => 'first@example.test',
            'password' => 'correct horse battery',
            'password_confirm' => 'correct horse battery',
        ]);
    }

    private function owner(): int
    {
        $id = $this->session->get(AuthenticationMiddleware::SESSION_USER_ID);
        self::assertIsInt($id);

        return $id;
    }

    private function householdName(): string
    {
        $user = (new UserRepository($this->db))->findByEmail('first@example.test');
        self::assertNotNull($user);

        $household = (new HouseholdRepository($this->db))
            ->findById((new MembershipRepository($this->db))->findAllForUser($user->id)[0]->householdId);
        self::assertNotNull($household);

        return $household->name;
    }

    private function preferences(int $userId): NotificationPreferences
    {
        $clock = new SystemClock(new DateTimeZone('UTC'));

        return (new NotificationPreferenceRepository($this->db, $clock))->findForUser($userId);
    }

    /**
     * @return list<\App\Domain\Entity\NotificationChannel>
     */
    private function channels(int $userId): array
    {
        $channels = new NotificationChannelRepository(
            $this->db,
            new SystemClock(new DateTimeZone('UTC')),
            TestKeys::ring(),
        );

        return $channels->findAllForUser($userId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function body(string $method, string $path, array $body = []): string
    {
        return (string) $this->request($method, $path, $body)->getBody();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $request = $request
                ->withParsedBody($body)
                ->withHeader(
                    CsrfTokenManager::HEADER_NAME,
                    $this->container->get(CsrfTokenManager::class)->token(),
                );
        }

        return $this->app->handle($request);
    }
}
