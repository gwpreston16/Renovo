<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AlertType;
use App\Domain\BudgetPeriod;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Persistence\Database;
use App\Repository\BudgetRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\NotificationChannelRepository;
use App\Repository\NotificationRouteRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\BudgetMonthService;
use App\Service\BudgetService;
use App\Service\ExchangeRateService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\SpendChartService;
use App\Service\SpendHistoryService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The Budgets screen, rendered through the real application on a frozen day
 * (15 June), so the calendar month a card reads is fixed.
 *
 * Figures are checked against the services that produce them — the card's
 * projection against the reconstruction plus the forecast, the history
 * against the dashboard chart's months — and states at their boundaries. Every
 * assertion reads inside the card or section it is about.
 */
final class BudgetScreenTest extends DatabaseTestCase
{
    private const TODAY = '2026-06-15 09:00:00';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
    private int $contributorId;
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
            Clock::class => FrozenClock::at(self::TODAY),
        ]);

        $settings = $this->container()->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->markRatesAttempted(new DateTimeImmutable());

        (new ExchangeRateRepository($this->db))->replaceBase('GBP', [
            ExchangeRate::of('GBP', 'EUR', 2 * ExchangeRate::SCALE),
        ], new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $now = new DateTimeImmutable();
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $this->contributorId = $users->create('contributor@example.test', 'Contributor', 'hash', false, $now);
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, $now);
        $this->householdId = (new HouseholdRepository($this->db))->create('House', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->contributorId, Role::Contributor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->signIn($this->ownerId);
    }

    // ------------------------------------------------------------ figures

    public function testTheProjectionIsTheReconstructionPlusTheForecastForTheSubjectAndPeriod(): void
    {
        // Charged on the 1st (already this month) and due on the 20th.
        $this->monthly('Early', 1200, '2026-07-01', '2025-01-01');
        $this->monthly('Late', 800, '2026-06-20', '2025-01-20');
        $this->monthly('Contributor thing', 500, '2026-06-25', '2025-01-25', $this->contributorId);
        $id = $this->budget(['name' => 'Everything', 'amount' => '100.00', 'subject_user_id' => 'household']);
        $mine = $this->budget([
            'name' => 'Contributor only',
            'amount' => '100.00',
            'subject_user_id' => (string) $this->contributorId,
        ]);

        foreach ([[$id, null], [$mine, $this->contributorId]] as [$budgetId, $subject]) {
            $expected = $this->expectedMonth($subject);
            $card = $this->card($budgetId);

            self::assertStringContainsString(
                $this->gbp($expected['projected']),
                $this->text($card, 'budget-projected'),
            );
            self::assertStringContainsString(
                $this->gbp($expected['charged']) . ' charged so far and ' . $this->gbp($expected['projected'])
                    . ' projected',
                $this->attr($card, 'budget-bar', 'aria-label'),
            );
        }

        // The whole household: 12 charged on the 1st, 8 and 5 to come.
        self::assertSame(['charged' => 1200, 'projected' => 2500], $this->expectedMonth(null));
    }

    /**
     * Each budgeted member used to make the page load the household's
     * subscriptions, price history and ledger again, twice (K182). They are
     * loaded once now; the splits are the one read a member's budget adds.
     */
    public function testMoreBudgetedMembersLoadNothingMore(): void
    {
        $this->monthly('Early', 1200, '2026-07-01', '2025-01-01');
        $this->monthly('Late', 800, '2026-06-20', '2025-01-20', $this->contributorId);
        $this->budget(['name' => 'Everything', 'amount' => '100.00', 'subject_user_id' => 'household']);
        $months = $this->container()->get(BudgetMonthService::class);
        $db = $this->container()->get(Database::class);
        $scope = $this->ownerScope();

        $before = $db->statementCount();
        $months->all($scope);
        $one = $db->statementCount() - $before;

        foreach ([$this->ownerId, $this->editorId, $this->contributorId] as $member) {
            $this->budget(['name' => 'Member', 'amount' => '50.00', 'subject_user_id' => (string) $member]);
        }

        $before = $db->statementCount();
        $rows = $months->all($scope);
        $four = $db->statementCount() - $before;

        self::assertCount(4, $rows);
        self::assertLessThanOrEqual($one + 3, $four, 'Nothing grows with the budgeted members.');
    }

    public function testAYearlyBudgetReadsTheCalendarYear(): void
    {
        // January to June charged (six), July to December still to come (six).
        $this->monthly('Monthly', 1000, '2026-07-01', '2025-01-01');
        $id = $this->budget(['name' => 'Year', 'period' => 'annual', 'amount' => '200.00']);

        $card = $this->card($id);
        self::assertStringContainsString('£120.00', $this->text($card, 'budget-projected'));
        self::assertStringContainsString('£60.00 charged so far', $this->attr($card, 'budget-bar', 'aria-label'));
        self::assertStringContainsString('Yearly', $this->text($card, 'budget-meta'));
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function boundaries(): iterable
    {
        // £85 projected against an 85% warning threshold.
        yield 'just under the threshold' => ['100.59', 85, 'ok', '£15.59 left'];
        yield 'at the threshold' => ['100.00', 85, 'warn', '85% used — past the 85% warning'];
        yield 'at the limit' => ['85.00', 85, 'warn', '100% used — past the 85% warning'];
        yield 'just over the limit' => ['84.99', 85, 'bad', 'Over by £0.01'];
    }

    /**
     * @dataProvider boundaries
     */
    public function testTheStateAtItsBoundaries(string $limit, int $threshold, string $state, string $note): void
    {
        $this->monthly('Bill', 8500, '2026-06-20', '2025-01-20');
        $id = $this->budget(['amount' => $limit, 'warn_threshold_percent' => (string) $threshold]);

        $card = $this->card($id);
        self::assertStringContainsString('is-' . $state, (string) $card->getAttribute('class'));
        self::assertSame($note, $this->text($card, 'budget-note'));
    }

    public function testJustUnderTheThresholdIsNotRoundedIntoAWarning(): void
    {
        // 84.5% of the limit rounds to 85 but is not past an 85% threshold.
        $this->monthly('Bill', 8500, '2026-06-20', '2025-01-20');
        $id = $this->budget(['amount' => '100.59', 'warn_threshold_percent' => '85']);

        $card = $this->card($id);
        self::assertSame('On track', $this->text($card, 'badge'));
        self::assertStringContainsString('84%', $this->text($card, 'budget-foot'));
    }

    public function testOverOnlyIfTrialsConvertSaysSo(): void
    {
        $this->monthly('Bill', 4000, '2026-06-20', '2025-01-20');
        $this->subscriptions()->create($this->ownerScope(), [
            'name' => 'Trial',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => '2026-06-25',
            'converts_to_price_minor' => 1999,
            'is_active' => true,
        ], []);
        $id = $this->budget(['amount' => '50.00']);

        $card = $this->card($id);
        self::assertSame('Over', $this->text($card, 'badge'));
        self::assertSame('Projected £59.99 if trials convert — over by £9.99', $this->text($card, 'budget-note'));
        self::assertSame('1', $this->text($this->page(), 'tile-over'));
    }

    public function testANullProjectionIsUnavailableAndNeverOnTrack(): void
    {
        $this->subscriptions()->create($this->ownerScope(), [
            'name' => 'Franc thing',
            'price_minor' => 5000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-20',
            'start_date' => '2025-01-20',
            'is_active' => true,
        ], []);
        $id = $this->budget(['amount' => '100.00']);

        $card = $this->card($id);
        self::assertSame('Projection unavailable — no rate for XOF', $this->text($card, 'budget-note'));
        self::assertStringNotContainsString('On track', $card->textContent);
        self::assertSame('0', $this->text($this->page(), 'tile-on-track'));
        self::assertSame('0', $this->text($this->page(), 'tile-over'));
    }

    // ------------------------------------------------------------ tiles and history

    public function testTheTilesCountVisibleBudgetsAndTheHouseholdLimitNeedsAHouseholdBudget(): void
    {
        $this->monthly('Bill', 8500, '2026-06-20', '2025-01-20');
        $this->budget(['name' => 'Roomy', 'amount' => '500.00']);
        $this->budget(['name' => 'Close', 'amount' => '90.00']);

        $page = $this->page();
        self::assertSame('1', $this->text($page, 'tile-on-track'));
        self::assertSame('1', $this->text($page, 'tile-warning'));
        self::assertSame('0', $this->text($page, 'tile-over'));
        self::assertNull($this->find($page, 'tile-household-limit'));
        self::assertNull($this->find($page, 'budget-history'));

        $this->budget(['name' => 'Household', 'amount' => '600.00', 'subject_user_id' => 'household']);
        $page = $this->page();
        self::assertStringContainsString('£600.00', $this->text($page, 'tile-household-limit'));
        self::assertSame('2', $this->text($page, 'tile-on-track'));
    }

    public function testTheHistoryIsTheDashboardChartsMonthsAgainstTheHouseholdLimit(): void
    {
        $this->monthly('Bill', 8500, '2026-06-20', '2025-01-20');
        $this->monthly('Quarterly-ish', 3000, '2026-06-02', '2026-03-02');
        $this->budget(['name' => 'Household', 'amount' => '100.00', 'subject_user_id' => 'household']);

        $chart = $this->container()->get(SpendChartService::class)->window($this->ownerScope(), 5, 0, 10000);
        self::assertIsArray($chart['bars']);
        self::assertCount(6, $chart['bars']);
        $over = count(array_filter($chart['bars'], static fn (array $bar): bool => $bar['total_minor'] > 10000));
        // March to June carry both; January and February only the bill.
        self::assertSame(4, $over);

        $history = $this->find($this->page(), 'budget-history');
        self::assertNotNull($history);
        self::assertStringContainsString(
            'against the £100.00 limit · over in ' . $over . ' of 6',
            $this->text($history, 'muted'),
        );
        foreach ($chart['bars'] as $bar) {
            self::assertStringContainsString((string) $bar['total_display'], $history->textContent);
        }
    }

    public function testAHistoryWhoseLimitCannotBeConvertedCountsNothing(): void
    {
        $this->monthly('Bill', 8500, '2026-06-20', '2025-01-20');
        $this->container()->get(BudgetService::class)->create($this->ownerScope(), [
            'name' => 'Household',
            'period' => 'monthly',
            'amount' => '100.00',
            'currency' => 'XOF',
            'warn_threshold_percent' => '85',
            'subject_user_id' => 'household',
        ]);

        $history = $this->find($this->page(), 'budget-history');
        self::assertNotNull($history);
        self::assertStringNotContainsString('over in', $history->textContent);
        self::assertStringContainsString('against the', $history->textContent);
    }

    public function testNoHistoryOrHouseholdLimitInIsolatedMode(): void
    {
        $this->budget(['name' => 'Household', 'amount' => '100.00', 'subject_user_id' => 'household']);
        $this->isolate();

        $page = $this->page();
        self::assertNull($this->find($page, 'budget-history'));
        self::assertNull($this->find($page, 'tile-household-limit'));
    }

    // ------------------------------------------------------------ alerts line

    public function testTheAlertsLineIsTheOwnersRoutingByChannelType(): void
    {
        $id = $this->budget(['amount' => '100.00']);
        self::assertSame('Alerts off', $this->text($this->card($id), 'budget-alerts'));

        $channels = $this->container()->get(NotificationChannelRepository::class);
        $email = $channels->create($this->ownerId, 'email', 'My private inbox', ['address' => 'me@example.test']);
        $line = $this->text($this->card($id), 'budget-alerts');
        self::assertSame('Alert when projected over · Email', $line);
        self::assertStringNotContainsString('private inbox', $line);
        self::assertStringNotContainsString('me@example.test', $this->card($id)->textContent);

        // Routed away from budget alerts: off again.
        $this->container()->get(NotificationRouteRepository::class)->replaceForUser($this->ownerId, [
            $email => [AlertType::Renewal],
        ]);
        self::assertSame('Alerts off', $this->text($this->card($id), 'budget-alerts'));
    }

    public function testTheAlertsLineIsTheOwnersEvenToAnotherViewer(): void
    {
        $id = $this->budget(['amount' => '100.00']);
        $this->container()->get(NotificationChannelRepository::class)
            ->create($this->editorId, 'email', 'Editor inbox', ['address' => 'ed@example.test']);

        $this->signIn($this->editorId);
        self::assertSame('Alerts off', $this->text($this->card($id), 'budget-alerts'));
    }

    // ------------------------------------------------------------ subjects

    public function testAContributorIsOfferedOnlyThemselves(): void
    {
        $this->signIn($this->contributorId);
        $form = (string) $this->request('GET', '/budgets/new')->getBody();

        self::assertStringContainsString('value="' . $this->contributorId . '"', $form);
        self::assertStringNotContainsString('value="household"', $form);
        self::assertStringNotContainsString('value="' . $this->ownerId . '"', $form);
    }

    public function testAnEditorInIsolatedModeIsOfferedOnlyThemselves(): void
    {
        $this->isolate();
        $this->signIn($this->editorId);
        $form = (string) $this->request('GET', '/budgets/new')->getBody();

        self::assertStringContainsString('value="' . $this->editorId . '"', $form);
        self::assertStringNotContainsString('value="household"', $form);
        self::assertStringNotContainsString('value="' . $this->ownerId . '"', $form);
    }

    public function testAForgedSubjectIsRefused(): void
    {
        $this->signIn($this->contributorId);
        foreach (['household', (string) $this->ownerId] as $subject) {
            $response = $this->request('POST', '/budgets', [
                'name' => 'Forged',
                'period' => 'monthly',
                'amount' => '10.00',
                'warn_threshold_percent' => '85',
                'subject_user_id' => $subject,
            ]);
            self::assertSame(422, $response->getStatusCode(), $subject);
        }

        $this->isolate();
        $this->signIn($this->ownerId);
        $response = $this->request('POST', '/budgets', [
            'name' => 'Forged',
            'period' => 'monthly',
            'amount' => '10.00',
            'subject_user_id' => 'household',
        ]);
        self::assertSame(422, $response->getStatusCode());

        self::assertSame([], (new BudgetRepository($this->db))->findAll($this->ownerScope(), false));
    }

    public function testAnEditKeepsASubjectTheViewerCannotChoose(): void
    {
        $id = $this->budget(['name' => 'Household', 'amount' => '100.00', 'subject_user_id' => 'household']);
        $this->isolate();

        $form = (string) $this->request('GET', '/budgets/' . $id . '/edit')->getBody();
        self::assertStringContainsString('data-locked-subject', $form);
        self::assertStringNotContainsString('name="subject_user_id"', $form);

        $response = $this->request('POST', '/budgets/' . $id, [
            'name' => 'Renamed',
            'period' => 'monthly',
            'amount' => '100.00',
            'warn_threshold_percent' => '85',
        ]);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        $saved = $this->container()->get(BudgetService::class)->find($this->ownerScope(), $id);
        self::assertNotNull($saved);
        self::assertSame('Renamed', $saved->name);
        self::assertNull($saved->subjectUserId);
    }

    // ------------------------------------------------------------ the form

    public function testANewBudgetIsInTheBaseCurrencyAndAnEditKeepsCurrencyAndActiveFlag(): void
    {
        $response = $this->request('POST', '/budgets', [
            'name' => 'Base',
            'period' => 'monthly',
            'amount' => '10.00',
            'warn_threshold_percent' => '80',
            'subject_user_id' => (string) $this->ownerId,
        ]);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        $budgets = $this->container()->get(BudgetService::class);
        $created = $budgets->all($this->ownerScope())[0];
        self::assertSame('GBP', $created->amount->currency);
        self::assertSame(80, $created->warnThresholdPercent);

        // One set in euros, and switched off, before the form lost both fields.
        $euro = $budgets->create($this->ownerScope(), [
            'name' => 'Euro',
            'period' => 'monthly',
            'amount' => '50.00',
            'currency' => 'EUR',
            'is_active' => '0',
        ]);
        $edit = (string) $this->request('GET', '/budgets/' . $euro . '/edit')->getBody();
        self::assertStringContainsString('Limit (EUR)', $edit);

        $this->request('POST', '/budgets/' . $euro, [
            'name' => 'Euro renamed',
            'period' => 'monthly',
            'amount' => '60.00',
            'warn_threshold_percent' => '85',
            'subject_user_id' => (string) $this->ownerId,
        ]);
        $saved = $budgets->find($this->ownerScope(), $euro);
        self::assertNotNull($saved);
        self::assertSame('Euro renamed', $saved->name);
        self::assertSame('EUR', $saved->amount->currency);
        self::assertFalse($saved->isActive);
    }

    public function testTheFormIsAFragmentForTheDialog(): void
    {
        $id = $this->budget(['amount' => '100.00']);

        foreach (['/budgets/new', '/budgets/' . $id . '/edit'] as $path) {
            $body = (string) $this->request('GET', $path, [], ['HX-Request' => 'true'])->getBody();
            self::assertStringNotContainsString('<html', $body, $path);
            self::assertStringContainsString('id="budget-form"', $body, $path);
            self::assertStringContainsString('type="range"', $body, $path);
        }

        $page = (string) $this->request('GET', '/budgets')->getBody();
        self::assertStringContainsString('data-dialog-url="/budgets/' . $id . '/edit"', $page);
        self::assertStringContainsString('<dialog id="budget-dialog"', $page);
        self::assertStringContainsString('class="dialog-mark"', $page);
        self::assertStringContainsString('id="budget-dialog-subtitle"', $page);
    }

    public function testWhoseSpendingIsARadioGroupWithOneChoiceChecked(): void
    {
        $id = $this->budget(['amount' => '100.00', 'subject_user_id' => 'household']);

        foreach (['/budgets/new', '/budgets/' . $id . '/edit'] as $path) {
            $body = (string) $this->request('GET', $path, [], ['HX-Request' => 'true'])->getBody();
            self::assertStringNotContainsString('<select id="budget-subject"', $body, $path);
            self::assertSame(
                1,
                preg_match_all('/<input type="radio" name="subject_user_id"[^>]*\bchecked\b/', $body),
                $path,
            );
        }

        $edit = (string) $this->request('GET', '/budgets/' . $id . '/edit', [], ['HX-Request' => 'true'])->getBody();
        self::assertMatchesRegularExpression('/value="household" id="budget-subject"\s+checked/', $edit);
    }

    // ------------------------------------------------------------ permissions

    public function testAViewerSeesNoNewOrEditAndIsRefusedEveryWrite(): void
    {
        $id = $this->budget(['amount' => '100.00']);

        $this->signIn($this->viewerId);
        $page = (string) $this->request('GET', '/budgets')->getBody();
        self::assertStringNotContainsString('href="/budgets/new"', $page);
        self::assertStringNotContainsString('/budgets/' . $id . '/edit', $page);
        self::assertStringContainsString('data-budget="' . $id . '"', $page);

        foreach (
            [
                ['GET', '/budgets/new'],
                ['GET', '/budgets/' . $id . '/edit'],
                ['POST', '/budgets'],
                ['POST', '/budgets/' . $id],
                ['POST', '/budgets/' . $id . '/delete'],
            ] as [$method, $path]
        ) {
            $status = $this->request($method, $path, ['name' => 'x'])->getStatusCode();
            self::assertSame(403, $status, $method . ' ' . $path);
        }

        self::assertNotNull($this->container()->get(BudgetService::class)->find($this->ownerScope(), $id));
    }

    public function testAContributorCanEditOnlyTheirOwnBudget(): void
    {
        $household = $this->budget(['name' => 'Household', 'amount' => '100.00', 'subject_user_id' => 'household']);

        $this->signIn($this->contributorId);
        $this->request('POST', '/budgets', [
            'name' => 'Mine',
            'period' => 'monthly',
            'amount' => '10.00',
            'subject_user_id' => (string) $this->contributorId,
        ]);
        $page = (string) $this->request('GET', '/budgets')->getBody();

        self::assertStringContainsString('href="/budgets/new"', $page);
        self::assertMatchesRegularExpression(
            '#<main[^>]*>.*?<div class="page-header">\s*<p[^>]*>[^<]*</p>\s*'
                . '<a class="button button-primary" href="/budgets/new"#s',
            $page,
            'New budget heads the content, filled with the accent.'
        );
        self::assertStringContainsString('<div class="topbar-page-actions"></div>', $page);
        self::assertStringNotContainsString('/budgets/' . $household . '/edit', $page);
        self::assertSame(404, $this->request('GET', '/budgets/' . $household . '/edit')->getStatusCode());
        self::assertMatchesRegularExpression('#/budgets/\d+/edit#', $page);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{charged: int, projected: int}
     */
    private function expectedMonth(?int $subject): array
    {
        $scope = $this->ownerScope();
        $today = new DateTimeImmutable('2026-06-15');
        $history = $this->container()->get(SpendHistoryService::class)
            ->charges($scope, new DateTimeImmutable('2026-05-31'), $today->modify('-1 day'), $subject)['charges'];
        $ahead = array_filter(
            $this->container()->get(ForecastService::class)->charges($scope, 1, $subject),
            static fn (array $charge): bool => $charge['date'] <= new DateTimeImmutable('2026-06-30'),
        );

        $sum = static fn (array $charges): int => array_sum(array_map(
            static fn (array $charge): int => $charge['amount']->amountMinor,
            $charges,
        ));

        // Everything here is in pounds, so no conversion stands between the
        // sum and the figure; the rates service agrees.
        self::assertNotNull($this->container()->get(ExchangeRateService::class)->rateFor('GBP', 'GBP'));

        return ['charged' => $sum($history), 'projected' => $sum($history) + $sum($ahead)];
    }

    private function monthly(string $name, int $minor, string $next, string $start, ?int $owner = null): void
    {
        $this->subscriptions()->create($this->scopeFor($owner ?? $this->ownerId), [
            'name' => $name,
            'price_minor' => $minor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next,
            'start_date' => $start,
            'is_active' => true,
        ], []);
    }

    /**
     * @param array<string, string> $input
     */
    private function budget(array $input): int
    {
        return $this->container()->get(BudgetService::class)->create($this->ownerScope(), $input + [
            'name' => 'Budget',
            'period' => BudgetPeriod::Monthly->value,
            'warn_threshold_percent' => '85',
        ]);
    }

    private function page(): DOMXPath
    {
        $response = $this->request('GET', '/budgets');
        self::assertSame(200, $response->getStatusCode());

        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $response->getBody());
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    private function card(int $id): DOMElement
    {
        $node = $this->page()->query('//article[@data-budget="' . $id . '"]')?->item(0);
        self::assertInstanceOf(DOMElement::class, $node, 'No card for budget ' . $id);

        return $node;
    }

    /**
     * An element by class inside a card, or by `data-tile` / class on the page.
     */
    private function find(DOMXPath|DOMElement $context, string $name): ?DOMElement
    {
        if ($context instanceof DOMXPath) {
            $tile = str_starts_with($name, 'tile-') ? substr($name, 5) : null;
            $query = $tile !== null
                ? '//*[@data-tile="' . $tile . '"]'
                : '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")]';
            $node = $context->query($query)?->item(0);
        } else {
            $xpath = new DOMXPath($context->ownerDocument ?? new DOMDocument());
            $node = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $name . ' ")]',
                $context,
            )?->item(0);
        }

        return $node instanceof DOMElement ? $node : null;
    }

    private function text(DOMXPath|DOMElement $context, string $name): string
    {
        $node = $this->find($context, $name);
        self::assertNotNull($node, 'Nothing called ' . $name);

        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    private function attr(DOMElement $context, string $name, string $attribute): string
    {
        $node = $this->find($context, $name);
        self::assertNotNull($node, 'Nothing called ' . $name);

        return $node->getAttribute($attribute);
    }

    private function gbp(int $minor): string
    {
        return '£' . number_format($minor / 100, 2);
    }

    private function isolate(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);
    }

    private function subscriptions(): SubscriptionRepository
    {
        return new SubscriptionRepository($this->db);
    }

    private function ownerScope(): Scope
    {
        return $this->scopeFor($this->ownerId);
    }

    private function scopeFor(int $userId): Scope
    {
        $role = match ($userId) {
            $this->ownerId => Role::OwnerAdmin,
            $this->editorId => Role::Editor,
            $this->contributorId => Role::Contributor,
            default => Role::Viewer,
        };

        return Scope::forMember($userId, false, $this->householdId, $role, IsolationMode::Shared);
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
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $body = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($method !== 'GET') {
            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
