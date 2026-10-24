<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Domain\Visibility;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\BudgetService;
use App\Service\CategoryService;
use App\Service\ForecastService;
use App\Service\HouseholdDashboardService;
use App\Service\InstanceSettingsService;
use App\Service\SpendTrendService;
use App\Service\SplitService;
use App\Support\AvatarTone;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The Household dashboard: this month so far, the year's headline figures, the
 * next thirty days, who pays what, and the year against its budget pace.
 *
 * Frozen on Monday 15 June 2026. The household, all in pounds:
 *
 * - Ada's "Early month", £20 on the 5th since January 2025 — already charged.
 * - Ada's "Due today", £7 on the 15th since January 2026 — due, not charged.
 * - Ada's "Split plan", £40 on the 20th since January 2025, split 3:1 with
 *   Bram, so £30 is Ada's and £10 Bram's.
 * - Ada's trial, converting to £12.99 a month on the 25th.
 * - Bram's "Bram private", £6 on the 28th, private to Bram as its payer.
 */
final class HouseholdDashboardTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $adaId;
    private int $bramId;
    private int $cleoId;
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
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->markRatesAttempted(new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->adaId = $users->create('ada@example.test', 'Ada Lovelace', 'hash', false, new DateTimeImmutable());
        $this->bramId = $users->create('bram@example.test', 'Bram', 'hash', false, new DateTimeImmutable());
        $this->cleoId = $users->create('cleo@example.test', 'Cleo', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Ivy Cottage', $this->adaId);
        $memberships->create($this->householdId, $this->adaId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->bramId, Role::Editor);
        $memberships->create($this->householdId, $this->cleoId, Role::Viewer);

        foreach ([$this->adaId, $this->bramId, $this->cleoId] as $userId) {
            $users->updatePreferences($userId, ['dashboard_view' => 'household']);
        }

        $this->monthly($this->adaId, 'Early month', 2000, '2026-07-05', '2025-01-05');
        $this->monthly($this->adaId, 'Due today', 700, '2026-06-15', '2026-01-15');
        $split = $this->monthly($this->adaId, 'Split plan', 4000, '2026-06-20', '2025-01-20');

        $this->container()->get(SplitService::class)->update($this->scopeFor($this->adaId), $split, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$this->adaId => 3, $this->bramId => 1],
        ]);

        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->adaId), [
            'name' => 'Trial plan',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => '2026-06-25',
            'converts_to_price_minor' => 1299,
            'is_active' => true,
        ], []);

        $this->monthly($this->bramId, 'Bram private', 600, '2026-06-28', '2025-01-28', [
            'visibility' => Visibility::Payer->value,
            'payer_user_id' => $this->bramId,
        ]);
    }

    /**
     * Charged is what fell before today; the £7 due today is still due.
     */
    public function testAlreadyChargedCountsOnlyChargesBeforeToday(): void
    {
        $month = $this->household($this->adaId)['month'];

        self::assertSame(2000, $month['charged']['combined']['amount_minor']);
        // £7 today, £40 on the 20th, £12.99 when the trial converts.
        self::assertSame(700 + 4000 + 1299, $month['due']['combined']['amount_minor']);

        $body = $this->page($this->adaId);
        self::assertStringContainsString('June so far', $body);
        self::assertStringContainsString('already charged of £79.99 due this month', $body);
        self::assertStringNotContainsString(' paid of ', $body);
    }

    /**
     * Bram's private row is his alone: in his month, not in Ada's.
     */
    public function testAPrivateRowCountsOnlyForItsPayer(): void
    {
        self::assertSame(5999, $this->household($this->adaId)['month']['due']['combined']['amount_minor']);
        self::assertSame(5999 + 600, $this->household($this->bramId)['month']['due']['combined']['amount_minor']);

        self::assertStringNotContainsString('Bram private', $this->page($this->adaId));
    }

    /**
     * Ada carries £7 + £20 + £30 = £57 a month and Bram his £10 of the split
     * plan — his private £6 is not Ada's to see — so 85% and 15%. The custom
     * 3:1 weights are what put £10 against Bram rather than half of £40.
     */
    public function testWhoPaysHonoursCustomWeightsAndHidesPrivateRows(): void
    {
        $whoPays = $this->household($this->adaId)['who_pays'];
        $figures = [];
        foreach ($whoPays['rows'] as $row) {
            $figures[$row['member']->displayName] = $row['monthly']['combined']['amount_minor'];
        }

        self::assertSame(5700, $figures['Ada Lovelace']);
        self::assertSame(1000, $figures['Bram']);
        self::assertSame(85, $whoPays['percents'][$this->adaId]);
        self::assertSame(15, $whoPays['percents'][$this->bramId]);

        // In Bram's own view his private row is his: £16.
        foreach ($this->household($this->bramId)['who_pays']['rows'] as $row) {
            if ($row['member']->userId === $this->bramId) {
                self::assertSame(1600, $row['monthly']['combined']['amount_minor']);
            }
        }
    }

    public function testTheYearToDateIsComparedWithTheSameStretchLastYear(): void
    {
        $ytd = $this->household($this->adaId)['hero']['year_to_date'];

        // Six £20s, five £40s and five £7s since January; last year the same
        // stretch had six £20s and five £40s.
        self::assertSame(12000 + 20000 + 3500, $ytd['combined']['amount_minor']);
        self::assertSame(11, $ytd['change_percent']);
        self::assertStringContainsString('+11% against the same period last year', $this->page($this->adaId));
    }

    public function testNextTwelveMonthsIsTheForecast(): void
    {
        $expected = 0;
        $forecast = $this->container()->get(ForecastService::class);
        foreach ($forecast->monthly($this->scopeFor($this->adaId), 12) as $month) {
            $expected += (int) $month['combined_minor'];
        }

        self::assertSame($expected, $this->household($this->adaId)['hero']['year_ahead']['combined']['amount_minor']);
    }

    public function testTrialsConvertingIsTheirMonthlyCost(): void
    {
        $trials = $this->household($this->adaId)['hero']['trials'];

        self::assertSame(1299, $trials['combined']['amount_minor']);
        self::assertSame(1, $trials['count']);
        self::assertStringContainsString('a month from 1 trial', $this->page($this->adaId));
    }

    public function testTheTimelineHoldsTheNextThirtyDays(): void
    {
        $timeline = $this->household($this->adaId)['timeline'];

        // 15 June, 20 June, the conversion on the 25th, 5 July and 15 July.
        self::assertSame(5, $timeline['count']);
        self::assertSame('0%', $timeline['markers'][0]['position']);
        self::assertStringContainsString('5 charges', $this->page($this->adaId));
    }

    /**
     * The ticks past today are the dates a week, two, three and thirty days
     * on, not a count of days; the trial's marker colour has a key, and the
     * list beneath names the conversion in words.
     */
    public function testTheTimelineIsTickedWithDatesAndKeysTheTrialColour(): void
    {
        $card = $this->card($this->page($this->adaId), 'aria-labelledby="timeline-heading"');

        foreach (['Today', '22 Jun', '29 Jun', '6 Jul', '15 Jul'] as $tick) {
            self::assertMatchesRegularExpression('/class="timeline-tick[^"]*"[^>]*>\s*' . $tick . '\s*</', $card);
        }
        self::assertStringNotContainsString('+7', $card);

        self::assertStringContainsString('class="plain timeline-legend"', $card);
        self::assertStringContainsString('<span class="timeline-key is-trial"></span>Trial ends', $card);
        self::assertMatchesRegularExpression(
            '/<li class="timeline-item is-trial">.*?Trial ends: <\/span>Trial plan/s',
            $card,
        );
        self::assertSame(1, substr_count($card, 'timeline-item is-trial'), 'Only the conversion is a trial.');
    }

    public function testByCategoryDrawsEachBarInItsCategorysColour(): void
    {
        $category = $this->container()->get(CategoryService::class)
            ->create($this->scopeFor($this->adaId), 'Streaming', '#AA3366');
        $this->monthly($this->adaId, 'Films', 900, '2026-06-22', '2025-01-22', ['category_id' => $category]);

        $card = $this->card($this->page($this->adaId), 'aria-labelledby="by-category-heading"');

        self::assertMatchesRegularExpression(
            '/Streaming.*?class="meter-fill" data-css-width="\d+%" data-css-background="#aa3366"/s',
            $card,
        );
        // Uncategorised has no colour of its own, so it keeps the accent.
        self::assertMatchesRegularExpression('/Uncategorised.*?class="meter-fill" data-css-width="\d+%"/s', $card);
    }

    public function testWhoPaysDrawsEachMembersBarInTheirTone(): void
    {
        $card = $this->card($this->page($this->adaId), 'class="who-pays-group"');

        foreach ([$this->adaId, $this->bramId] as $userId) {
            self::assertStringContainsString('meter-fill avatar-tone-' . AvatarTone::of($userId) . '"', $card);
        }
    }

    public function testThePaceCardAppearsOnlyWithAHouseholdBudget(): void
    {
        self::assertNull($this->household($this->adaId)['pace']);
        self::assertStringNotContainsString('pace-card', $this->page($this->adaId));

        // A member's own budget is not a household pace.
        $this->budget('Mine', '1200.00', 'annual', (string) $this->adaId);
        self::assertNull($this->household($this->adaId)['pace']);

        $this->budget('House', '1200.00', 'annual', BudgetService::SUBJECT_HOUSEHOLD);
        $pace = $this->household($this->adaId)['pace'];

        self::assertNotNull($pace);
        self::assertSame(35500, $pace['spent']->amountMinor);
        // £1,200 over 165 of 365 days, to yesterday.
        self::assertSame(54247, $pace['pace']->amountMinor);
        self::assertStringContainsString('£187.47 under pace', $this->page($this->adaId));
    }

    public function testAMonthlyHouseholdBudgetMarksTheMonthAndPacesTheYear(): void
    {
        $this->budget('House', '100.00', 'monthly', BudgetService::SUBJECT_HOUSEHOLD);

        $household = $this->household($this->adaId);

        self::assertNotNull($household['month']['bar']['budget_position']);
        self::assertSame(120000, $household['pace']['budget']->amountMinor);
        self::assertStringContainsString(
            'twelve times the £100.00 monthly household budget',
            $this->page($this->adaId),
        );
    }

    public function testAnUnconvertibleCurrencyWithholdsTheMonthsBar(): void
    {
        $this->monthly($this->adaId, 'Exotic', 5000, '2026-06-20', '2025-01-20', ['currency' => 'XOF']);

        $month = $this->household($this->adaId)['month'];

        self::assertNull($month['bar']);
        self::assertSame(['XOF'], $month['total']['combined']['unconvertible']);
        self::assertStringContainsString('no exchange rate is available for XOF', $this->page($this->adaId));
    }

    public function testByCategoryDrawsProportionBars(): void
    {
        $body = $this->page($this->adaId);

        self::assertStringContainsString('By category', $body);
        self::assertStringContainsString('Uncategorised', $body);
    }

    public function testAViewerSeesTheViewWithoutMemberFigures(): void
    {
        $body = $this->page($this->cleoId);

        self::assertStringContainsString('June so far', $body);
        self::assertStringNotContainsString('who-pays-group', $body);
    }

    /**
     * Twelve complete months, June 2025 to May 2026: June itself is not over
     * and is left off. Everything is uncategorised, so one line — £20 and £40
     * every month, and £7 more from January.
     */
    public function testSpendOverTimeIsTwelveCompleteMonthsByCategory(): void
    {
        $trend = $this->trend($this->adaId, 'category');

        self::assertSame('category', $trend['by']);
        self::assertTrue($trend['is_drawable']);
        self::assertCount(12, $trend['months']);
        self::assertSame('2025-06', $trend['months'][0]->format('Y-m'));
        self::assertSame('2026-05', $trend['months'][11]->format('Y-m'));

        self::assertCount(1, $trend['series']);
        self::assertNull($trend['series'][0]['name'], 'The uncategorised line.');
        self::assertSame(
            [...array_fill(0, 7, 6000), ...array_fill(0, 5, 6700)],
            $trend['series'][0]['points'],
        );
        self::assertSame(6700, $trend['series'][0]['latest_minor']);
        self::assertSame('var(--s1)', $trend['series'][0]['colour']);
    }

    /**
     * The five biggest categories are lines of their own and the rest share
     * one. A category's own colour is kept; the colourless take the palette in
     * turn.
     */
    public function testSpendOverTimeDrawsTheTopCategoriesAndGathersTheRest(): void
    {
        $categories = $this->container()->get(CategoryService::class);
        $scope = $this->scopeFor($this->adaId);

        $streaming = $categories->create($scope, 'Streaming', '#AA3366');
        $this->monthly($this->adaId, 'Films', 9000, '2026-06-22', '2025-01-22', ['category_id' => $streaming]);
        foreach (['Aa', 'Bb', 'Cc', 'Dd', 'Ee', 'Ff'] as $index => $name) {
            $id = $categories->create($scope, $name, null);
            $this->monthly($this->adaId, $name . ' plan', 100 * ($index + 1), '2026-06-10', '2025-01-10', [
                'category_id' => $id,
            ]);
        }

        $series = $this->trend($this->adaId, 'category')['series'];
        $names = array_map(static fn (array $line): ?string => $line['name'], $series);

        // Streaming £90, Uncategorised ~£60, then Ff, Ee, Dd; Cc, Bb and Aa gathered.
        self::assertSame(['Streaming', null, 'Ff', 'Ee', 'Dd', null], $names);
        self::assertSame('#aa3366', strtolower((string) $series[0]['colour']));
        self::assertSame('var(--s1)', $series[1]['colour']);
        self::assertSame('var(--s2)', $series[2]['colour']);
        self::assertTrue($series[5]['is_other']);
        self::assertSame(3, $series[5]['other_count']);
        self::assertSame('var(--s-other)', $series[5]['colour']);
        self::assertSame(300 + 200 + 100, $series[5]['latest_minor']);
    }

    /**
     * By member, each line is that member's share: Ada's £20 + £7 + £30 and
     * Bram's £10 of the split plan. His private £6 is his own, counted in his
     * view and not in Ada's.
     */
    public function testSpendOverTimeByMemberIsEachMembersShare(): void
    {
        $latest = static function (array $trend): array {
            $figures = [];
            foreach ($trend['series'] as $line) {
                $figures[$line['name']] = $line['latest_minor'];
            }

            return $figures;
        };

        $trend = $this->trend($this->adaId, 'member');
        self::assertSame('member', $trend['by']);
        self::assertTrue($trend['is_member_available']);

        $figures = $latest($trend);
        self::assertSame(5700, $figures['Ada Lovelace']);
        self::assertSame(1000, $figures['Bram']);

        foreach ($trend['series'] as $line) {
            self::assertNotNull($line['tone'], 'A member line is drawn in their avatar tone.');
        }

        self::assertSame(1600, $latest($this->trend($this->bramId, 'member'))['Bram']);
    }

    /**
     * A Viewer is not shown who carries what, so there is no member view to
     * offer; asking for it, or for anything else, is the category view.
     */
    public function testSpendOverTimeFallsBackToCategories(): void
    {
        self::assertSame('category', $this->trend($this->adaId, 'nonsense')['by']);

        $viewer = $this->trend($this->cleoId, 'member');
        self::assertFalse($viewer['is_member_available']);
        self::assertSame('category', $viewer['by']);
        self::assertStringNotContainsString('?trend=member', $this->page($this->cleoId));
    }

    public function testSpendOverTimeIsNotDrawnWhenAMonthCannotBeTotalled(): void
    {
        $this->monthly($this->adaId, 'Exotic', 5000, '2026-06-20', '2025-01-20', ['currency' => 'XOF']);

        $trend = $this->trend($this->adaId, 'category');

        self::assertFalse($trend['is_drawable']);
        self::assertSame(['XOF'], $trend['unconvertible']);
        self::assertStringContainsString(
            'No exchange rate for XOF, so these months cannot be added up in one currency.',
            $this->page($this->adaId),
        );
    }

    /**
     * The two views are links: without script the page comes back on the one
     * chosen; with htmx the card comes back alone.
     */
    public function testSpendOverTimeSwitchesViewsByLink(): void
    {
        $card = $this->card($this->page($this->adaId, '/?trend=member'), 'id="spend-trend"');
        self::assertMatchesRegularExpression('/href="\/\?trend=member#spend-trend"[^>]*aria-current="true"/s', $card);
        self::assertStringContainsString('Bram', $card);
        self::assertStringContainsString('class="trend-line avatar-tone-', $card);

        $partial = $this->page($this->adaId, '/?trend=member', ['HX-Request' => 'true']);
        self::assertStringStartsWith('<section class="card trend-card" id="spend-trend"', ltrim($partial));
        self::assertStringNotContainsString('June so far', $partial);

        // Back to a pushed URL htmx has no snapshot of: it re-fetches, and puts
        // the answer in place of the whole body, so the answer is the page.
        $restored = $this->page($this->adaId, '/?trend=member', [
            'HX-Request' => 'true',
            'HX-History-Restore-Request' => 'true',
        ]);
        self::assertStringContainsString('June so far', $restored);
    }

    /**
     * In ISOLATED mode Ada's lines are drawn from her own rows alone, and with
     * only her own share to show there is no member view to offer.
     */
    public function testSpendOverTimeUnderIsolationIsYourOwnRows(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);
        $this->monthly($this->bramId, 'Bram shared', 5000, '2026-06-12', '2025-01-12');

        $scope = Scope::forMember($this->adaId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Isolated);
        $trend = $this->container()->get(SpendTrendService::class)->trend($scope, 'member');

        self::assertSame('category', $trend['by']);
        self::assertFalse($trend['is_member_available']);
        // The rows Ada can see at their full cost — £20, £7 and the £40 plan
        // she owns — as without isolation; none of Bram's £50.
        self::assertSame(6700, $trend['series'][0]['latest_minor']);
    }

    /** Each member gets a card of their own. */
    public function testWhoPaysIsACardPerMember(): void
    {
        $group = $this->card($this->page($this->adaId), 'class="who-pays-group"');

        self::assertStringContainsString('id="member-card-' . $this->adaId . '"', $group);
        self::assertStringContainsString('id="member-card-' . $this->bramId . '"', $group);
        self::assertGreaterThanOrEqual(2, substr_count($group, '<article class="card member-card"'));
    }

    /** The card whose opening tag carries `$marker`, up to its close. */
    private function card(string $html, string $marker): string
    {
        $start = strpos($html, $marker);
        self::assertNotFalse($start, 'The card was drawn.');

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function monthly(
        int $ownerId,
        string $name,
        int $priceMinor,
        string $next,
        string $start,
        array $extra = [],
    ): int {
        return (new SubscriptionRepository($this->db))->create($this->scopeFor($ownerId), $extra + [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next,
            'start_date' => $start,
            'anchor_day' => (int) (new DateTimeImmutable($start))->format('j'),
            'is_active' => true,
        ], []);
    }

    private function budget(string $name, string $amount, string $period, string $subject): void
    {
        $this->container()->get(BudgetService::class)->create($this->scopeFor($this->adaId), [
            'name' => $name,
            'period' => $period,
            'amount' => $amount,
            'currency' => 'GBP',
            'subject_user_id' => $subject,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function household(int $userId): array
    {
        return $this->container()->get(HouseholdDashboardService::class)->household($this->scopeFor($userId));
    }

    /**
     * @return array<string, mixed>
     */
    private function trend(int $userId, string $by): array
    {
        return $this->container()->get(SpendTrendService::class)->trend($this->scopeFor($userId), $by);
    }

    /**
     * @param array<string, string> $headers
     */
    private function page(int $userId, string $path = '/', array $headers = []): string
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1']);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->app->handle($request);
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function scopeFor(int $userId): Scope
    {
        $role = match ($userId) {
            $this->adaId => Role::OwnerAdmin,
            $this->cleoId => Role::Viewer,
            default => Role::Editor,
        };

        return Scope::forMember($userId, false, $this->householdId, $role, IsolationMode::Shared);
    }
}
