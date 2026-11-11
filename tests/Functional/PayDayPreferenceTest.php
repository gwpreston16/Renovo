<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * A member's pay day (Phase 87), saved from Profile → Preferences for the
 * member who is signed in and nobody else, refused when it is not a real date
 * or is more than a year ahead, and cleared by an empty value.
 */
final class PayDayPreferenceTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private UserRepository $users;

    private int $ownerId;
    private int $viewerId;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            Clock::class => FrozenClock::at('2026-06-15 09:00:00'),
        ]);

        $settings = $this->container()->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->setDemoMode(false);

        $this->users = new UserRepository($this->db);
        $now = new DateTimeImmutable();
        $this->ownerId = $this->users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->viewerId = $this->users->create('viewer@example.test', 'Viewer', 'hash', false, $now);
        $this->householdId = (new HouseholdRepository($this->db))->create('Household', $this->ownerId);
        $memberships = new MembershipRepository($this->db);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);
    }

    public function testAPayDaySavesForTheMemberAndShowsInTheForm(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request('POST', '/profile/preferences', ['pay_day' => '2026-05-25']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('2026-05-25', $this->stored($this->ownerId));
        self::assertNull($this->stored($this->viewerId), "The owner's pay day reached another member.");
        self::assertMatchesRegularExpression(
            '~<input type="date" id="pay_day" name="pay_day"[^>]*value="2026-05-25"~',
            (string) $this->request('GET', '/profile')->getBody(),
        );
    }

    /**
     * Its own form with its own Save: a date input fires `change` as each
     * typed part makes a valid date, so it must not ride the save-on-change
     * form beside it.
     */
    public function testThePayDayIsNotOnTheSaveOnChangeForm(): void
    {
        $this->signIn($this->ownerId);
        $page = (string) $this->request('GET', '/profile')->getBody();

        self::assertMatchesRegularExpression(
            '~<form method="post" action="/profile/preferences" class="stack pay-day-form">.*?name="pay_day"~s',
            $page,
        );
        self::assertDoesNotMatchRegularExpression(
            '~hx-trigger="change"(?:(?!</form>).)*name="pay_day"~s',
            $page,
        );
    }

    public function testAViewerSetsTheirOwnPayDay(): void
    {
        $this->signIn($this->viewerId);

        $this->request('POST', '/profile/preferences', ['pay_day' => '2026-06-01']);

        self::assertSame('2026-06-01', $this->stored($this->viewerId));
    }

    public function testNamingAnotherMemberInTheFormChangesOnlyYourOwn(): void
    {
        $this->signIn($this->viewerId);

        $this->request('POST', '/profile/preferences', [
            'pay_day' => '2026-06-01',
            'user_id' => (string) $this->ownerId,
            'id' => (string) $this->ownerId,
        ]);

        self::assertNull($this->stored($this->ownerId));
        self::assertSame('2026-06-01', $this->stored($this->viewerId));
    }

    public function testAnEmptyValueClearsItAndAnAbsentOneLeavesIt(): void
    {
        $this->signIn($this->ownerId);
        $this->request('POST', '/profile/preferences', ['pay_day' => '2026-05-25']);

        $this->request('POST', '/profile/preferences', ['theme' => 'dark']);
        self::assertSame('2026-05-25', $this->stored($this->ownerId));

        $this->request('POST', '/profile/preferences', ['pay_day' => '']);
        self::assertNull($this->stored($this->ownerId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'not a date' => ['next friday'];
        yield 'a day the month does not have' => ['2026-02-30'];
        yield 'another format' => ['25/05/2026'];
        yield 'more than a year ahead' => ['2027-06-16'];
        yield 'year zero, which neither engine stores' => ['0000-01-01'];
        yield 'before 1900' => ['1899-12-31'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testANonsensePayDayIsRefusedAndTheRestOfTheFormSaves(string $value): void
    {
        $this->signIn($this->ownerId);
        $this->request('POST', '/profile/preferences', ['pay_day' => '2026-05-25']);

        $response = $this->request('POST', '/profile/preferences', ['pay_day' => $value, 'theme' => 'dark']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('2026-05-25', $this->stored($this->ownerId));
        self::assertSame('dark', $this->users->findById($this->ownerId)?->theme);
        self::assertStringContainsString(
            'Enter a real date for your pay day',
            (string) $this->request('GET', '/profile')->getBody(),
            'The refusal reaches the member.',
        );
    }

    public function testExactlyAYearAheadIsAllowed(): void
    {
        $this->signIn($this->ownerId);

        $this->request('POST', '/profile/preferences', ['pay_day' => '2027-06-15']);

        self::assertSame('2027-06-15', $this->stored($this->ownerId));
    }

    private function stored(int $userId): ?string
    {
        return $this->users->findById($userId)?->payDay?->format('Y-m-d');
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, string> $body
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
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
