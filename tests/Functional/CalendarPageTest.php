<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\NoticePeriod;
use App\Domain\Role;
use App\Domain\TokenScopes;
use App\Domain\Visibility;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\ApiTokenService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SubscriptionService;
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
 * The calendar screen, rendered through the real application on a frozen day:
 * Tuesday 15 September 2026.
 *
 * Every assertion reads inside the part of the page it is about — a day's
 * cell, the open-day panel, the summary, the feed card — so a figure the
 * summary shows cannot satisfy a test about the grid.
 */
final class CalendarPageTest extends DatabaseTestCase
{
    private const TODAY = '2026-09-15 09:00:00';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
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
        $settings->setDemoMode(false);

        // One pound is two euros; there is no rate for dollars at all.
        (new ExchangeRateRepository($this->db))->replaceBase('GBP', [
            ExchangeRate::of('GBP', 'EUR', 2 * ExchangeRate::SCALE),
        ], new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $now = new DateTimeImmutable();
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, $now);
        $this->householdId = (new HouseholdRepository($this->db))->create('House', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->signIn($this->ownerId);
    }

    // ---------------------------------------------------------------- grid

    /**
     * @return iterable<string, array{int, string, string, int}>
     */
    public static function weekStarts(): iterable
    {
        // September 2026 begins on a Tuesday: the first cell is the Sunday or
        // the Monday before it, and the 1st is the third or second column.
        yield 'Sunday first' => [0, 'Sunday', '30', 2];
        yield 'Monday first' => [1, 'Monday', '31', 1];
    }

    /**
     * @dataProvider weekStarts
     */
    public function testTheGridStartsOnThePreferredWeekday(
        int $weekStart,
        string $firstHeading,
        string $firstCell,
        int $columnOfTheFirst,
    ): void {
        (new UserRepository($this->db))->updatePreferences($this->ownerId, ['week_start' => $weekStart]);

        $page = $this->page('/calendar');

        $heading = $page->query('//table[contains(@class, "calendar-grid")]//th//abbr')?->item(0);
        self::assertInstanceOf(DOMElement::class, $heading);
        self::assertSame($firstHeading, $heading->getAttribute('title'));

        $cells = $page->query('//table[contains(@class, "calendar-grid")]/tbody/tr[1]/td');
        self::assertNotFalse($cells);
        $first = $cells->item(0);
        self::assertInstanceOf(DOMElement::class, $first);
        self::assertStringContainsString('is-outside', $first->getAttribute('class'));
        self::assertSame($firstCell, $this->squash($first->textContent));

        $theFirst = $cells->item($columnOfTheFirst);
        self::assertInstanceOf(DOMElement::class, $theFirst);
        self::assertStringStartsWith('1 September', $this->squash($theFirst->textContent));
    }

    public function testDaysOutsideTheMonthAreDimmedAndInert(): void
    {
        $page = $this->page('/calendar');

        $outside = $page->query('//td[contains(@class, "is-outside")]');
        self::assertNotFalse($outside);
        self::assertGreaterThan(0, $outside->length);

        foreach ($outside as $cell) {
            self::assertInstanceOf(DOMElement::class, $cell);
            self::assertSame(0, $cell->getElementsByTagName('a')->length, 'A neighbouring month is not a link.');
        }
    }

    public function testTodayIsMarkedInWordsAsWellAsColour(): void
    {
        $today = $this->cell($this->page('/calendar'), '2026-09-15');

        self::assertStringContainsString('is-today', $today->getAttribute('class'));
        self::assertStringContainsString('(Today)', $this->squash($today->textContent));
        self::assertStringContainsString('aria-current="date"', $this->html($today));
    }

    public function testAScheduledRiseShowsTheNewAmountFromItsEffectiveDate(): void
    {
        $id = $this->monthly('Streaming', 1000, '2026-09-20');
        $this->container()->get(PriceHistoryService::class)->schedule($this->ownerScope(), $id, [
            'price' => '15.00',
            'currency' => 'GBP',
            'effective_from' => '2026-10-01',
        ]);

        self::assertSame('£10.00', $this->dayTotal($this->page('/calendar'), '2026-09-20'));
        self::assertSame('£15.00', $this->dayTotal($this->page('/calendar?month=2026-10'), '2026-10-20'));
    }

    public function testCancelByChipsAppearOnlyForASubscriptionWithANoticePeriod(): void
    {
        // Thirty days' notice on a charge due 20 October: the last day to act
        // is 20 September.
        $this->monthly('Gym', 4000, '2026-10-20', notice: 30);
        $this->monthly('Cancel any time', 1000, '2026-10-20');

        $september = $this->page('/calendar');
        $chips = $this->chips($this->cell($september, '2026-09-20'));
        self::assertSame([['cancel-by', 'Gym']], $chips);

        foreach (['/calendar', '/calendar?month=2026-10'] as $path) {
            foreach ($this->allChips($this->page($path)) as [$kind, $name]) {
                self::assertFalse(
                    $kind === 'cancel-by' && $name === 'Cancel any time',
                    'A subscription with no notice period has no cancel-by date.',
                );
            }
        }

        // A deadline is not a charge: September costs nothing.
        self::assertSame('0 charges', $this->text($september, 'calendar-count'));
        self::assertSame('0', $this->summary($september, 'count'));
    }

    public function testPausedCancelledAndAnotherMembersPrivateRowsNeverAppear(): void
    {
        $this->monthly('Mine', 1000, '2026-09-18');
        $this->monthly('Paused thing', 1000, '2026-09-18', active: false);
        $cancelled = $this->monthly('Cancelled thing', 1000, '2026-09-18');
        $this->container()->get(SubscriptionService::class)->cancel($this->ownerScope(), $cancelled);
        $this->monthly('Therapy', 1000, '2026-09-18', owner: $this->editorId, private: true);

        $owner = $this->body('/calendar?day=2026-09-18');
        self::assertStringContainsString('Mine', $owner);
        foreach (['Paused thing', 'Cancelled thing', 'Therapy'] as $absent) {
            self::assertStringNotContainsString($absent, $owner);
        }

        // Its payer sees it.
        $this->signIn($this->editorId);
        self::assertStringContainsString('Therapy', $this->body('/calendar?day=2026-09-18'));
    }

    public function testEachChipCarriesAWordAndAnIconAsWellAsItsColour(): void
    {
        $this->monthly('Streaming', 1000, '2026-09-20');
        $this->trial('Trial plan', '2026-09-22', 1299);
        $this->monthly('Gym', 4000, '2026-10-24', notice: 30);

        $page = $this->page('/calendar');
        $chips = $page->query(
            '//table[contains(@class, "calendar-grid")]//*[contains(concat(" ", @class, " "), " calendar-chip ")]',
        );
        self::assertNotFalse($chips);
        self::assertSame(3, $chips->length);

        $words = ['is-charge' => 'Charge:', 'is-trial' => 'Trial ends:', 'is-cancel-by' => 'Cancel by:'];
        foreach ($chips as $chip) {
            self::assertInstanceOf(DOMElement::class, $chip);
            $kind = (string) preg_replace('/.*\b(is-[a-z-]+)\b.*/', '$1', $chip->getAttribute('class'));

            self::assertSame(1, $chip->getElementsByTagName('svg')->length, 'A chip has no icon.');
            self::assertStringContainsString(
                $words[$kind],
                $this->text($chip, 'visually-hidden'),
                'A chip has no word for its kind.',
            );
        }

        self::assertSame('Charge Trial ends Cancel by', $this->text($page, 'calendar-legend'));
    }

    public function testADayWithMoreThanThreeItemsSaysHowManyMore(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            $this->monthly($name, 100, '2026-09-25');
        }

        $page = $this->page('/calendar?day=2026-09-25');
        $cell = $this->cell($page, '2026-09-25');

        self::assertCount(3, $this->chips($cell));
        self::assertSame('+2 more', $this->text($cell, 'calendar-more'));
        self::assertSame(5, $this->numberOf($this->panel($page), 'calendar-item'));
    }

    public function testTheHeaderAndSummaryFollowThePerCurrencyRule(): void
    {
        $this->monthly('Pounds', 1000, '2026-09-20');
        // Thirty euros is fifteen pounds: the 22nd is the heavier day.
        $this->monthly('Euros', 3000, '2026-09-22', currency: 'EUR');

        $page = $this->page('/calendar');
        self::assertSame('2 charges · £25.00 (€30.00 · £10.00)', $this->text($page, 'calendar-count'));
        self::assertSame('£25.00 (€30.00 · £10.00)', $this->summary($page, 'total'));
        self::assertSame('2', $this->summary($page, 'count'));
        self::assertSame('22 Sept · £15.00', $this->summary($page, 'heaviest'));

        // A day holding dollars, which have no rate, might be the heaviest:
        // the figure is left out rather than naming another day.
        $this->monthly('Dollars', 500, '2026-09-25', currency: 'USD');

        $page = $this->page('/calendar');
        self::assertSame('€30.00 · £10.00 · US$5.00', $this->summary($page, 'total'));
        self::assertNull($this->find($page, '//*[@data-summary="heaviest"]'));
    }

    /**
     * K87: a varying £ bill beside a fixed € one. The £ subtotal and the
     * combined figure are drawn "≈"; the € subtotal is exact and is not.
     */
    public function testOnlyTheVaryingCurrencyIsMarked(): void
    {
        $id = $this->monthly('Energy', 1000, '2026-09-20');
        $this->db->execute('UPDATE subscriptions SET amount_varies = TRUE WHERE id = :id', ['id' => $id]);
        $this->monthly('Euros', 3000, '2026-09-22', currency: 'EUR');

        $page = $this->page('/calendar');
        self::assertSame('2 charges · ≈ £25.00 (€30.00 · ≈ £10.00)', $this->text($page, 'calendar-count'));
        self::assertSame('≈ £25.00 (€30.00 · ≈ £10.00)', $this->summary($page, 'total'));
    }

    // ------------------------------------------------------------ navigation

    public function testPreviousIsDisabledOnTheCurrentMonth(): void
    {
        $nav = $this->find($this->page('/calendar'), '//nav[contains(@class, "calendar-nav")]');
        self::assertNotNull($nav);

        self::assertStringNotContainsString('rel="prev"', $this->html($nav));
        self::assertMatchesRegularExpression('~<button[^>]*disabled[^>]*>.*?Previous month~s', $this->html($nav));

        $october = $this->find($this->page('/calendar?month=2026-10'), '//nav[contains(@class, "calendar-nav")]');
        self::assertNotNull($october);
        self::assertStringContainsString('href="/calendar?month=2026-09"', $this->html($october));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function months(): iterable
    {
        yield 'last month' => ['2026-08', 404];
        yield 'last year' => ['2025-09', 404];
        yield 'past the horizon' => ['2027-10', 404];
        yield 'the horizon' => ['2027-09', 200];
        yield 'not a month' => ['2026-13', 200];
        yield 'nonsense' => ['soon', 200];
    }

    /**
     * @dataProvider months
     */
    public function testTheRouteRefusesMonthsOutsideTheHorizon(string $month, int $status): void
    {
        self::assertSame($status, $this->request('GET', '/calendar?month=' . $month)->getStatusCode());
    }

    public function testChoosingADayWorksWithoutScript(): void
    {
        $this->monthly('Streaming', 1000, '2026-09-20');

        $response = $this->request('GET', '/calendar?day=2026-09-20');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<body', (string) $response->getBody());

        $page = $this->parse((string) $response->getBody());
        $panel = $this->panel($page);
        self::assertSame('Sunday 20 September', $this->heading($panel));
        self::assertStringContainsString('Streaming', $this->squash($panel->textContent));
        self::assertStringContainsString('is-selected', $this->cell($page, '2026-09-20')->getAttribute('class'));

        // The same address is what htmx asks for.
        $link = $this->cell($page, '2026-09-20')->getElementsByTagName('a')->item(0);
        self::assertInstanceOf(DOMElement::class, $link);
        self::assertSame('/calendar?month=2026-09&day=2026-09-20', $link->getAttribute('href'));
        self::assertSame($link->getAttribute('href'), $link->getAttribute('hx-get'));
    }

    public function testTheOpenDayDefaultsToTodayThenTheFirstBusyDay(): void
    {
        $this->monthly('Streaming', 1000, '2026-10-20');
        $this->oneOff('Once', 500, '2026-09-18');

        self::assertSame('Tuesday 15 September', $this->heading($this->panel($this->page('/calendar'))));
        self::assertSame(
            'Tuesday 20 October',
            $this->heading($this->panel($this->page('/calendar?month=2026-10'))),
        );
        // A day in another month is not this month's open day.
        self::assertSame(
            'Tuesday 20 October',
            $this->heading($this->panel($this->page('/calendar?month=2026-10&day=2026-09-18'))),
        );

        $panel = $this->panel($this->page('/calendar?month=2026-09&day=2026-09-16'));
        self::assertSame('Nothing due on this day.', $this->text($panel, 'empty'));
    }

    public function testThePanelSaysWhatEachItemIs(): void
    {
        $this->subscriptions()->create($this->ownerScope(), [
            'name' => 'Streaming',
            'plan' => 'Premium',
            'price_minor' => 3000,
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-09-20',
            'anchor_day' => 20,
            'is_active' => true,
        ], []);
        $this->trial('Trial plan', '2026-09-20', 1299);
        $this->monthly('Gym', 4000, '2026-10-20', notice: 30);

        $panel = $this->panel($this->page('/calendar?day=2026-09-20'));
        $items = [];
        foreach ($this->all($panel, 'calendar-item') as $item) {
            $items[] = $this->squash($item->textContent);
        }

        self::assertSame([
            // The tile's initial and the owner's initials are decorative;
            // the name beside each is what is read.
            'G Gym Cancel by — notice period 30 days O Owner £40.00',
            'T Trial plan Trial ends — converts to paid O Owner £12.99',
            'S Streaming Charge: Premium O Owner €30.00 ≈ £15.00',
        ], $items);
    }

    public function testThePhoneListNamesEachDaysWeekdayAndHoldsOnlyBusyDays(): void
    {
        $this->monthly('Streaming', 1000, '2026-09-20');
        $this->monthly('Music', 500, '2026-09-22');

        $narrow = $this->find($this->page('/calendar'), '//*[contains(@class, "calendar-narrow")]');
        self::assertNotNull($narrow);

        $days = $this->all($narrow, 'calendar-list-day');
        self::assertCount(2, $days);
        self::assertSame(
            ['Sun', 'Tue'],
            array_map(static fn (DOMElement $day): string => $day->getAttribute('data-weekday'), $days),
        );
        // The heading is an ICU skeleton, so the locale data decides the
        // punctuation: newer ICU writes "Sun, 20 Sept", older "Sun 20 Sept".
        self::assertMatchesRegularExpression('/^Sun,? 20 Sept/', $this->text($days[0], 'calendar-list-heading'));
    }

    public function testAnEmptyMonthSaysSoInThePhoneList(): void
    {
        // Charged once, in September, so October has nothing in it.
        $this->oneOff('Once', 500, '2026-09-18');

        $empty = $this->find($this->page('/calendar?month=2026-10'), '//*[contains(@class, "calendar-narrow")]');
        self::assertNotNull($empty);
        self::assertSame('Nothing due this month.', $this->squash($empty->textContent));
    }

    public function testEverythingThatNavigatesSwapsTheWholeMonth(): void
    {
        $this->monthly('Streaming', 1000, '2026-09-20');

        $html = $this->body('/calendar');
        preg_match_all('/hx-target="([^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1]);
        self::assertSame(['#calendar-month'], array_values(array_unique($matches[1])));

        $fragment = (string) $this->request('GET', '/calendar?day=2026-09-20', [], ['HX-Request' => 'true'])->getBody();
        self::assertStringNotContainsString('<body', $fragment);
        self::assertStringContainsString('id="calendar-month"', $fragment);
        self::assertStringContainsString('id="calendar-day"', $fragment);
        self::assertStringContainsString('calendar-summary', $fragment);
        self::assertStringNotContainsString('id="calendar-feed"', $fragment);
    }

    // ------------------------------------------------------------------ feed

    public function testAMemberWithNoLinkIsOfferedOne(): void
    {
        $feed = $this->feedCard($this->page('/calendar'));

        self::assertSame('You have no feed link yet.', $this->text($feed, 'calendar-feed-state'));
        self::assertSame(0, $feed->getElementsByTagName('input')->length - $this->hiddenInputs($feed));
        self::assertStringContainsString('action="/calendar/feed-link"', $this->html($feed));
        self::assertStringNotContainsString('<details', $this->html($feed));
    }

    public function testANewLinkIsShownOnceAndWorks(): void
    {
        $this->monthly('Streaming', 1000, '2026-09-20');

        $response = $this->request('POST', '/calendar/feed-link');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/calendar#calendar-feed', $response->getHeaderLine('Location'));

        // An htmx swap does not carry the card, and does not spend the link.
        $this->request('GET', '/calendar?day=2026-09-20', [], ['HX-Request' => 'true']);

        $url = $this->feedUrl($this->page('/calendar'));
        self::assertNotNull($url, 'The new link was not shown.');
        // Built from APP_URL, not the request's host: behind a proxy the
        // request's host is the inside one.
        $appUrl = $this->container()->get('settings')['app']['url'];
        self::assertStringStartsWith(rtrim($appUrl, '/') . '/api/v1/calendar.ics?token=rnv_', $url);

        $feed = $this->request('GET', $this->pathOf($url));
        self::assertSame(200, $feed->getStatusCode());
        self::assertStringContainsString('Streaming', (string) $feed->getBody());

        // Shown once: the next visit says when it was made, not what it is.
        $card = $this->feedCard($this->page('/calendar'));
        self::assertNull($this->feedUrl($this->page('/calendar')));
        self::assertStringContainsString(
            'Your link was created on 15 Sept 2026.',
            $this->text($card, 'calendar-feed-state'),
        );
        self::assertStringContainsString('<details', $this->html($card));
    }

    public function testCreatingANewLinkRetiresTheOldOneImmediately(): void
    {
        // A read token the member made for themselves is not this screen's.
        $own = $this->container()->get(ApiTokenService::class)->issue(
            (new UserRepository($this->db))->findById($this->ownerId) ?? self::fail('No owner'),
            $this->householdId,
            'My calendar',
            TokenScopes::readEverything(),
        );

        $this->request('POST', '/calendar/feed-link');
        $old = $this->feedUrl($this->page('/calendar'));
        $this->request('POST', '/calendar/feed-link');
        $new = $this->feedUrl($this->page('/calendar'));

        self::assertNotNull($old);
        self::assertNotNull($new);
        self::assertNotSame($old, $new);
        self::assertSame(401, $this->request('GET', $this->pathOf($old))->getStatusCode());
        self::assertSame(200, $this->request('GET', $this->pathOf($new))->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v1/calendar.ics?token=' . $own)->getStatusCode());
    }

    public function testAViewerMayHaveAFeed(): void
    {
        $this->signIn($this->viewerId);

        self::assertSame(302, $this->request('POST', '/calendar/feed-link')->getStatusCode());
        $url = $this->feedUrl($this->page('/calendar'));
        self::assertNotNull($url);
        self::assertSame(200, $this->request('GET', $this->pathOf($url))->getStatusCode());
    }

    public function testCreatingALinkNeedsTheFormsToken(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/calendar/feed-link', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody([]);

        self::assertSame(400, $this->app->handle($request)->getStatusCode());
        self::assertNull($this->container()->get(ApiTokenService::class)->feedToken(
            (new UserRepository($this->db))->findById($this->ownerId) ?? self::fail('No owner'),
            $this->householdId,
        ));
    }

    public function testTheFeedLeavesOutPausedCancelledAndAnotherMembersPrivateRows(): void
    {
        $this->monthly('Mine', 1000, '2026-09-18');
        $this->monthly('Paused thing', 1000, '2026-09-18', active: false);
        $cancelled = $this->monthly('Cancelled thing', 1000, '2026-09-18');
        $this->container()->get(SubscriptionService::class)->cancel($this->ownerScope(), $cancelled);
        $this->monthly('Therapy', 1000, '2026-09-18', owner: $this->editorId, private: true);

        $this->request('POST', '/calendar/feed-link');
        $url = $this->feedUrl($this->page('/calendar'));
        self::assertNotNull($url);

        $response = $this->request('GET', $this->pathOf($url));
        $feed = str_replace("\r\n ", '', (string) $response->getBody());
        self::assertStringContainsString('Mine', $feed);
        foreach (['Paused thing', 'Cancelled thing', 'Therapy'] as $absent) {
            self::assertStringNotContainsString($absent, $feed);
        }
    }

    // --------------------------------------------------------------- fixtures

    private function monthly(
        string $name,
        int $minor,
        string $next,
        string $currency = 'GBP',
        ?int $notice = null,
        ?int $owner = null,
        bool $private = false,
        bool $active = true,
    ): int {
        return $this->subscriptions()->create($this->scopeFor($owner ?? $this->ownerId), array_filter([
            'name' => $name,
            'price_minor' => $minor,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next,
            'anchor_day' => (int) (new DateTimeImmutable($next))->format('j'),
            'notice_period_amount' => $notice,
            'notice_period_unit' => $notice === null ? null : NoticePeriod::UNIT_DAYS,
            'visibility' => $private ? Visibility::Payer->value : null,
            'is_active' => $active,
        ], static fn (mixed $value): bool => $value !== null), []);
    }

    private function oneOff(string $name, int $minor, string $date): int
    {
        return $this->subscriptions()->create($this->ownerScope(), [
            'name' => $name,
            'price_minor' => $minor,
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'billing_cycle' => null,
            'next_payment_date' => $date,
            'is_active' => true,
        ], []);
    }

    private function trial(string $name, string $ends, int $convertsTo): int
    {
        return $this->subscriptions()->create($this->ownerScope(), [
            'name' => $name,
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => $ends,
            'converts_to_price_minor' => $convertsTo,
            'is_active' => true,
        ], []);
    }

    // ---------------------------------------------------------------- reading

    private function cell(DOMXPath $page, string $date): DOMElement
    {
        $link = $page->query('//td/a[contains(@href, "day=' . $date . '")]')?->item(0);
        self::assertInstanceOf(DOMElement::class, $link, 'No cell for ' . $date);
        $cell = $link->parentNode;
        self::assertInstanceOf(DOMElement::class, $cell);

        return $cell;
    }

    private function heading(DOMElement $panel): string
    {
        $heading = $panel->getElementsByTagName('h2')->item(0);
        self::assertInstanceOf(DOMElement::class, $heading);

        return $this->squash($heading->textContent);
    }

    private function dayTotal(DOMXPath $page, string $date): string
    {
        return $this->text($this->cell($page, $date), 'calendar-day-total');
    }

    /**
     * @return list<array{string, string}> Each chip's kind and name.
     */
    private function chips(DOMElement $context): array
    {
        $chips = [];
        foreach ($this->all($context, 'calendar-chip') as $chip) {
            preg_match('/\bis-([a-z-]+)\b/', $chip->getAttribute('class'), $kind);
            $chips[] = [$kind[1] ?? '', $this->text($chip, 'calendar-chip-name')];
        }

        return $chips;
    }

    /**
     * @return list<array{string, string}>
     */
    private function allChips(DOMXPath $page): array
    {
        $grid = $this->find($page, '//table[contains(@class, "calendar-grid")]');
        self::assertNotNull($grid);

        return $this->chips($grid);
    }

    private function panel(DOMXPath $page): DOMElement
    {
        $panel = $this->find($page, '//*[@id="calendar-day"]');
        self::assertNotNull($panel, 'No open-day panel.');

        return $panel;
    }

    private function summary(DOMXPath $page, string $name): string
    {
        $node = $this->find($page, '//*[@data-summary="' . $name . '"]/dd');
        self::assertNotNull($node, 'No summary figure ' . $name);

        return $this->squash($node->textContent);
    }

    private function feedCard(DOMXPath $page): DOMElement
    {
        $card = $this->find($page, '//*[@id="calendar-feed"]');
        self::assertNotNull($card, 'No feed card.');

        return $card;
    }

    private function feedUrl(DOMXPath $page): ?string
    {
        $input = $this->find($page, '//input[@id="calendar-feed-url"]');

        return $input?->getAttribute('value');
    }

    /** The path and query of a feed address, to request it from the test app. */
    private function pathOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH) . '?' . (string) parse_url($url, PHP_URL_QUERY);
    }

    private function hiddenInputs(DOMElement $context): int
    {
        $hidden = 0;
        foreach ($context->getElementsByTagName('input') as $input) {
            $hidden += $input->getAttribute('type') === 'hidden' ? 1 : 0;
        }

        return $hidden;
    }

    private function find(DOMXPath $page, string $query): ?DOMElement
    {
        $node = $page->query($query)?->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * Every element carrying a class, inside a context.
     *
     * @return list<DOMElement>
     */
    private function all(DOMElement $context, string $class): array
    {
        $xpath = new DOMXPath($context->ownerDocument ?? new DOMDocument());
        $nodes = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]',
            $context,
        );

        $elements = [];
        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function numberOf(DOMElement $context, string $class): int
    {
        return count($this->all($context, $class));
    }

    private function text(DOMXPath|DOMElement $context, string $class): string
    {
        if ($context instanceof DOMXPath) {
            $node = $this->find(
                $context,
                '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]',
            );
        } else {
            $node = $this->all($context, $class)[0] ?? null;
        }

        self::assertNotNull($node, 'Nothing called ' . $class);

        return $this->squash($node->textContent);
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function html(DOMElement $element): string
    {
        return (string) $element->ownerDocument?->saveHTML($element);
    }

    private function page(string $path): DOMXPath
    {
        return $this->parse($this->body($path));
    }

    private function parse(string $html): DOMXPath
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    private function body(string $path): string
    {
        $response = $this->request('GET', $path);
        self::assertSame(200, $response->getStatusCode(), $path . ' did not render.');

        return (string) $response->getBody();
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
