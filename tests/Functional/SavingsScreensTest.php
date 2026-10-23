<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\DashboardCard;
use App\Domain\DashboardView;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\DashboardLayoutService;
use App\Service\InstanceSettingsService;
use App\Service\SubscriptionService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Phase 36's screens: the Savings card on Analytics, the optional dashboard
 * card, and the line on a subscription's cost page — each drawing what
 * `SavingsService` worked out, within the reader's scope.
 */
final class SavingsScreensTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private FrozenClock $clock;

    private int $ownerId;
    private int $bobId;
    private int $viewerId;
    private int $householdId;

    private int $spotifyId = 0;
    private int $gymId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->clock = FrozenClock::at('2026-02-01 09:00:00');

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            Clock::class => $this->clock,
        ]);

        $settings = $this->container()->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->markRatesAttempted(new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->bobId = $users->create('bob@example.test', 'Bob', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = (new HouseholdRepository($this->db))->create('Household', $this->ownerId);
        $memberships = new MembershipRepository($this->db);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->bobId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);
    }

    public function testAnalyticsShowsBothFiguresAndWhereTheyCameFrom(): void
    {
        $this->fixture();

        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-savings');

        // Three intro months at £5.99 against £11.99, and four months of a
        // £10.99 gym cancelled on 1 February.
        self::assertStringContainsString('£18.00', $card);
        self::assertStringContainsString('£43.96', $card);
        self::assertStringContainsString('£131.88 a year', $card);
        self::assertStringContainsString('/subscriptions/' . $this->spotifyId . '/money#payments', $card);
        self::assertStringContainsString('3 payments', $card);
        self::assertStringContainsString('4 payments avoided', $card);
    }

    public function testAViewerReadsTheHouseholdsSavings(): void
    {
        $this->fixture();
        // The owner's visit writes the ledger; a Viewer's never does.
        $this->get('/stats', $this->ownerId);

        $card = $this->section($this->body($this->get('/stats', $this->viewerId)), 'analytics-savings');

        self::assertStringContainsString('£18.00', $card);
        self::assertStringContainsString('£43.96', $card);
    }

    public function testInIsolatedModeTheCardHoldsOnlyTheReadersOwn(): void
    {
        $this->fixture();
        $bob = Scope::forMember($this->bobId, false, $this->householdId, Role::Editor, IsolationMode::Shared);
        $subscriptions = $this->container()->get(SubscriptionService::class);
        $id = $subscriptions->create($bob, $this->monthly('Bobs magazine', '7.00'));
        $subscriptions->cancel($bob, $id);
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $owner = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-savings');
        $bobs = $this->section($this->body($this->get('/stats', $this->bobId)), 'analytics-savings');

        self::assertStringNotContainsString('Bobs magazine', $owner);
        self::assertStringContainsString('Gym', $owner);
        self::assertStringContainsString('Bobs magazine', $bobs);
        self::assertStringNotContainsString('Gym', $bobs);
    }

    public function testWithNothingSavedTheCardSaysSo(): void
    {
        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-savings');

        self::assertStringContainsString('Nothing saved yet', $card);
    }

    public function testTheDashboardCardIsOffUntilItIsTurnedOn(): void
    {
        $this->fixture();

        self::assertStringNotContainsString('savings-card', $this->body($this->get('/', $this->ownerId)));

        $this->container()->get(DashboardLayoutService::class)
            ->setVisible($this->ownerId, DashboardView::Overview, DashboardCard::Savings, true);
        $html = $this->body($this->get('/', $this->ownerId));

        self::assertStringContainsString('savings-card', $html);
        self::assertStringContainsString('£18.00', $html);
        self::assertStringContainsString('href="/stats#analytics-savings"', $html);
    }

    public function testTheCostPageSaysWhatItsOffersSaved(): void
    {
        $this->fixture();

        $spotify = $this->body($this->get('/subscriptions/' . $this->spotifyId . '/money', $this->ownerId));
        self::assertStringContainsString('Saved £18.00 through offers', $spotify);
        self::assertStringContainsString('(normally £11.99)', $spotify);

        $gym = $this->body($this->get('/subscriptions/' . $this->gymId . '/money', $this->ownerId));
        self::assertStringNotContainsString('through offers', $gym);
    }

    /**
     * On 1 February: Spotify at an intro £5.99 from the 15th, £11.99 from
     * 1 May; a £10.99 gym billed on the 15th, cancelled today. Then the clock
     * moves to 1 June.
     */
    private function fixture(): void
    {
        $owner = Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        $subscriptions = $this->container()->get(SubscriptionService::class);

        $this->spotifyId = $subscriptions->create($owner, [
            'start_date' => '2026-02-15',
            'is_promotional' => '1',
            'offer_ends_on' => '2026-05-01',
            'offer_then_price' => '11.99',
        ] + $this->monthly('Spotify', '5.99'));

        $this->gymId = $subscriptions->create($owner, $this->monthly('Gym', '10.99'));
        $subscriptions->cancel($owner, $this->gymId);

        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
    }

    /**
     * @return array<string, string>
     */
    private function monthly(string $name, string $price): array
    {
        return [
            'name' => $name,
            'price' => $price,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-02-15',
            'start_date' => '2026-01-15',
        ];
    }

    private function section(string $html, string $id): string
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $node = (new DOMXPath($document))->query(sprintf("//*[@id='%s']", $id))?->item(0);

        if (!$node instanceof DOMElement) {
            self::fail(sprintf('The page has no section with id "%s".', $id));
        }

        return (string) $node->ownerDocument?->saveHTML($node);
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function body(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function get(string $path, int $userId): ResponseInterface
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }
}
