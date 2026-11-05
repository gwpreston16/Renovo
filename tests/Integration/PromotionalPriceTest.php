<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Api\Resource;
use App\Domain\InsightKind;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\PromoOffer;
use App\Domain\Role;
use App\Domain\SubscriptionFilter;
use App\Http\GuardedClient;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\ForecastService;
use App\Service\PriceHistoryService;
use App\Service\SpendInsightService;
use App\Service\SubscriptionExportService;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\FakeGuardedClient;
use DateTimeImmutable;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function DI\factory;

/**
 * Introductory and promotional prices: an offer and what follows it, written
 * together; moved and cleared without touching anything that has happened;
 * and named as the end of an offer, rather than a rise, wherever the step is
 * shown — while every price, total and forecast stays exactly what it was.
 */
final class PromotionalPriceTest extends DatabaseTestCase
{
    private SubscriptionService $subscriptions;
    private PriceHistoryService $prices;
    private PriceHistoryRepository $history;
    private SpendInsightService $insights;
    private ForecastService $forecast;
    private ContainerInterface $container;
    private FrozenClock $clock;

    private Scope $alice;
    private Scope $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-09-14 09:00:00');

        $this->container = $this->container();
        $this->subscriptions = $this->container->get(SubscriptionService::class);
        $this->prices = $this->container->get(PriceHistoryService::class);
        $this->history = $this->container->get(PriceHistoryRepository::class);
        $this->insights = $this->container->get(SpendInsightService::class);
        $this->forecast = $this->container->get(ForecastService::class);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $alice = $users->create('alice@example.test', 'Alice', 'hash', false, $this->clock->now());
        $bob = $users->create('bob@example.test', 'Bob', 'hash', false, $this->clock->now());
        $household = $households->create('Household', $alice);
        $memberships->create($household, $alice, Role::OwnerAdmin);
        $memberships->create($household, $bob, Role::Editor);

        $this->alice = Scope::forMember($alice, false, $household, Role::OwnerAdmin, IsolationMode::Shared);
        $this->bob = Scope::forMember($bob, false, $household, Role::Editor, IsolationMode::Shared);
    }

    public function testSavingAnOfferWritesTheIntroPriceAndItsEnd(): void
    {
        $id = $this->createOffer('Spotify', '5.99', '2026-12-01', '11.99');

        $rows = $this->prices->historyFor($this->alice, $id);
        self::assertCount(2, $rows);

        self::assertSame([599, true, PriceChangeSource::Initial], [
            $rows[0]->price->amountMinor,
            $rows[0]->isPromotional,
            $rows[0]->source,
        ]);
        self::assertSame([1199, false, PriceChangeSource::Scheduled, '2026-12-01'], [
            $rows[1]->price->amountMinor,
            $rows[1]->isPromotional,
            $rows[1]->source,
            $rows[1]->effectiveFrom->format('Y-m-d'),
        ]);
        self::assertSame(PriceHistoryService::OFFER_END_NOTE, $rows[1]->note);

        // The intro price is the price, today.
        self::assertSame(599, $this->subscriptions->find($this->alice, $id)?->price->amountMinor);

        $promotion = $this->prices->promotionFor($this->alice, $id);
        self::assertSame('2026-12-01', $promotion['ends_on']?->format('Y-m-d'));
        self::assertSame(1199, $promotion['then']?->amountMinor);
    }

    public function testAnOfferWhoseEndCannotBeWrittenWritesNeitherRow(): void
    {
        $id = $this->subscriptions->create($this->alice, $this->input('Spotify', '5.99'));
        $this->db->execute('DELETE FROM subscription_price_history WHERE subscription_id = :id', ['id' => $id]);

        // A date no engine will store: the second insert fails after the
        // first has succeeded, which is the case the transaction is for.
        $impossible = (new DateTimeImmutable('2026-01-01'))->setDate(6000000, 1, 1);

        try {
            $this->prices->recordInitialPrice(
                $this->alice,
                $id,
                Money::of(599, 'GBP'),
                new DateTimeImmutable('2026-09-01'),
                $this->alice->userId,
                new PromoOffer($impossible, Money::of(1199, 'GBP')),
            );
            self::fail('The impossible date was stored.');
        } catch (RuntimeException) {
            // PDOException on either engine.
        }

        self::assertSame([], $this->prices->historyFor($this->alice, $id));
    }

    public function testTheFlagChangesWordingAndNothingElse(): void
    {
        $offer = $this->createOffer('Offer', '5.99', '2026-12-01', '11.99');
        $plain = $this->subscriptions->create($this->alice, $this->input('Plain', '5.99'));
        $this->prices->schedule($this->alice, $plain, ['price' => '11.99', 'effective_from' => '2026-12-01']);

        $today = $this->clock->today();
        self::assertEquals(
            $this->prices->priceOn($this->alice, $plain, $today),
            $this->prices->priceOn($this->alice, $offer, $today),
        );
        self::assertSame(
            $this->subscriptions->find($this->alice, $plain)?->monthlyMinor(),
            $this->subscriptions->find($this->alice, $offer)?->monthlyMinor(),
        );

        $charges = ['Offer' => [], 'Plain' => []];
        $reasons = ['Offer' => [], 'Plain' => []];
        foreach ($this->forecast->charges($this->alice) as $charge) {
            $name = $charge['subscription']->name;
            $charges[$name][] = [$charge['date']->format('Y-m-d'), $charge['amount']->amountMinor];
            $reasons[$name][] = $charge['reason'];
        }

        self::assertNotEmpty($charges['Offer']);
        self::assertSame($charges['Plain'], $charges['Offer']);

        // The one difference: the first full-price charge is named.
        self::assertNotContains('offer_end', $reasons['Plain']);
        $first = array_search('offer_end', $reasons['Offer'], true);
        self::assertIsInt($first);
        self::assertSame(['2026-12-15', 1199], $charges['Offer'][$first]);
        self::assertSame(1, array_count_values($reasons['Offer'])['offer_end']);

        $changes = [];
        foreach ($this->forecast->scheduledChanges($this->alice, $this->byId()) as $row) {
            $changes[$row['subscription']->name] = $row['ends_offer'];
        }
        self::assertSame(['Offer' => true, 'Plain' => false], $changes);
    }

    public function testTheCalendarNamesTheFirstFullPriceCharge(): void
    {
        $this->createOffer('Spotify', '5.99', '2026-12-01', '11.99');
        $calendar = $this->container->get(\App\Service\CalendarService::class);

        $items = [];
        foreach (['2026-11-01', '2026-12-01', '2027-01-01'] as $month) {
            $grid = $calendar->month($this->alice, new DateTimeImmutable($month), \App\Domain\WeekStart::Monday);
            foreach ($grid['days'] as $day) {
                foreach ($day['items'] as $item) {
                    $items[$item['date']->format('Y-m-d')] = [$item['amount']->amountMinor, $item['ends_offer']];
                }
            }
        }

        self::assertSame([599, false], $items['2026-11-15']);
        self::assertSame([1199, true], $items['2026-12-15']);
        self::assertSame([1199, false], $items['2027-01-15']);
    }

    public function testAChargeOnTheDayAnOfferEndsIsTheFirstAtTheFullPrice(): void
    {
        // The offer ends on 15 Dec, which is also a charge date.
        $this->createOffer('Spotify', '5.99', '2026-12-15', '11.99');

        $byDate = [];
        foreach ($this->forecast->charges($this->alice) as $charge) {
            $byDate[$charge['date']->format('Y-m-d')] = [$charge['amount']->amountMinor, $charge['reason']];
        }

        self::assertSame([599, 'renewal'], $byDate['2026-11-15']);
        // The charge on the end date is the first at the full price...
        self::assertSame([1199, 'offer_end'], $byDate['2026-12-15']);
        // ...and the next is an ordinary renewal.
        self::assertSame([1199, 'renewal'], $byDate['2027-01-15']);
    }

    public function testMovingTheEndMovesTheAnnouncedRow(): void
    {
        $id = $this->createOffer('Spotify', '5.99', '2026-12-01', '11.99');
        $before = $this->prices->historyFor($this->alice, $id);

        $this->subscriptions->update($this->alice, $id, $this->input('Spotify', '5.99', [
            'is_promotional' => '1',
            'offer_ends_on' => '2027-01-01',
            'offer_then_price' => '12.99',
        ]));

        $after = $this->prices->historyFor($this->alice, $id);
        self::assertCount(2, $after);
        self::assertSame($before[1]->id, $after[1]->id);
        self::assertSame('2027-01-01', $after[1]->effectiveFrom->format('Y-m-d'));
        self::assertSame(1299, $after[1]->price->amountMinor);
        // The intro row is the one that already happened, and is as it was.
        self::assertEquals($before[0], $after[0]);
    }

    public function testARowWhoseDateHasPassedIsNeverRewritten(): void
    {
        $id = $this->createOffer('Spotify', '5.99', '2026-12-01', '11.99');
        $initial = $this->prices->historyFor($this->alice, $id)[0];
        $today = $this->clock->today();

        self::assertFalse($this->history->reviseAnnounced(
            $this->alice,
            $initial->id,
            new DateTimeImmutable('2027-01-01'),
            Money::of(1, 'GBP'),
            null,
            $today,
        ));
        self::assertFalse($this->history->deleteAnnounced($this->alice, $initial->id, $today));

        // Nor may an announced row be moved into the past, where it would
        // change the current price without a row saying so.
        $announced = $this->prices->historyFor($this->alice, $id)[1];
        self::assertFalse($this->history->reviseAnnounced(
            $this->alice,
            $announced->id,
            new DateTimeImmutable('2026-09-01'),
            $announced->price,
            null,
            $today,
        ));

        self::assertEquals([$initial, $announced], $this->prices->historyFor($this->alice, $id));
    }

    public function testANewOfferLeavesAChangeTheProviderAnnouncedAlone(): void
    {
        $id = $this->subscriptions->create($this->alice, $this->input('Netflix', '10.99'));
        $this->prices->schedule($this->alice, $id, ['price' => '12.99', 'effective_from' => '2026-11-01']);
        $announced = $this->prices->historyFor($this->alice, $id)[1];
        $offer = ['is_promotional' => '1', 'offer_then_price' => '11.99'];

        // An end beyond the announced rise would really be the rise's date.
        try {
            $this->subscriptions->update(
                $this->alice,
                $id,
                $this->input('Netflix', '5.99', ['offer_ends_on' => '2026-12-01'] + $offer),
            );
            self::fail('The offer was allowed to end beyond an announced change.');
        } catch (ValidationException $exception) {
            self::assertSame('error.offer.change_announced', $exception->errors()['offer_ends_on']->key ?? null);
        }
        self::assertCount(2, $this->prices->historyFor($this->alice, $id));

        // Before it, the offer writes its own end and the rise stays.
        $this->subscriptions->update(
            $this->alice,
            $id,
            $this->input('Netflix', '5.99', ['offer_ends_on' => '2026-10-15'] + $offer),
        );
        // And once it is running, it is its own end that moves.
        $this->subscriptions->update(
            $this->alice,
            $id,
            $this->input('Netflix', '5.99', ['offer_ends_on' => '2026-10-20'] + $offer),
        );

        $rows = $this->prices->historyFor($this->alice, $id);
        self::assertCount(4, $rows);
        self::assertTrue($rows[1]->isPromotional);
        self::assertSame(['2026-10-20', 1199, PriceHistoryService::OFFER_END_NOTE], [
            $rows[2]->effectiveFrom->format('Y-m-d'),
            $rows[2]->price->amountMinor,
            $rows[2]->note,
        ]);
        self::assertEquals($announced, $rows[3]);
    }

    public function testThePriceChangeFormRefusesAnOfferEndingBeyondAnAnnouncedChange(): void
    {
        $id = $this->subscriptions->create($this->alice, $this->input('Netflix', '10.99'));
        $this->prices->schedule($this->alice, $id, ['price' => '12.99', 'effective_from' => '2026-11-01']);

        $this->expectException(ValidationException::class);
        $this->prices->schedule($this->alice, $id, [
            'price' => '5.99',
            'effective_from' => '2026-10-01',
            'is_promotional' => '1',
            'offer_ends_on' => '2026-12-01',
            'offer_then_price' => '11.99',
        ]);
    }

    public function testRemovingTheFlagClearsItOnThatRowOnly(): void
    {
        $id = $this->createOffer('Spotify', '5.99', '2026-12-01', '11.99');
        $before = $this->prices->historyFor($this->alice, $id);

        $this->subscriptions->update($this->alice, $id, $this->input('Spotify', '5.99', ['is_promotional' => '0']));

        $after = $this->prices->historyFor($this->alice, $id);
        self::assertFalse($after[0]->isPromotional);
        // The change that followed it stays, an ordinary announced rise now.
        self::assertEquals($before[1], $after[1]);
        self::assertNull($this->prices->promotionFor($this->alice, $id));
    }

    public function testTakingAwayTheEndWithdrawsTheChangeItWrote(): void
    {
        $id = $this->createOffer('Spotify', '5.99', '2026-12-01', '11.99');

        $this->subscriptions->update($this->alice, $id, $this->input('Spotify', '5.99', [
            'is_promotional' => '1',
            'offer_ends_on' => '',
        ]));

        $rows = $this->prices->historyFor($this->alice, $id);
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->isPromotional);
    }

    public function testAnEditWithoutTheOfferFieldsLeavesTheOfferAlone(): void
    {
        // A bulk edit or an API PUT has no switch to send.
        $id = $this->createOffer('Spotify', '5.99', '2026-12-01', '11.99');
        $before = $this->prices->historyFor($this->alice, $id);

        $this->subscriptions->update($this->alice, $id, $this->input('Spotify renamed', '5.99'));

        self::assertEquals($before, $this->prices->historyFor($this->alice, $id));
    }

    public function testThenCostsDefaultsToTheOrdinaryPrice(): void
    {
        $id = $this->subscriptions->create($this->alice, $this->input('Spotify', '11.99'));

        self::assertSame('11.99', $this->prices->offerFormValues($this->alice, $id)['offer_then_price']);

        $this->subscriptions->update($this->alice, $id, $this->input('Spotify', '5.99', [
            'is_promotional' => '1',
            'offer_ends_on' => '2026-12-01',
            'offer_then_price' => '',
        ]));

        $rows = $this->prices->historyFor($this->alice, $id);
        self::assertCount(3, $rows);
        self::assertTrue($rows[1]->isPromotional);
        self::assertSame(1199, $rows[2]->price->amountMinor);
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidOffers(): array
    {
        return [
            'ends today' => [
                ['offer_ends_on' => '2026-09-14', 'offer_then_price' => '9'],
                'offer_ends_on',
                'error.offer.ends_past',
            ],
            'ends before it starts' => [
                ['start_date' => '2026-10-01', 'offer_ends_on' => '2026-09-20', 'offer_then_price' => '9'],
                'offer_ends_on',
                'error.offer.ends_past',
            ],
            'not a date' => [
                ['offer_ends_on' => 'soon', 'offer_then_price' => '9'],
                'offer_ends_on',
                'error.date.invalid',
            ],
            'no then price' => [['offer_ends_on' => '2026-12-01'], 'offer_then_price', 'error.offer.then_required'],
            'negative then price' => [
                ['offer_ends_on' => '2026-12-01', 'offer_then_price' => '-1'],
                'offer_then_price',
                'error.price.negative',
            ],
        ];
    }

    /**
     * @dataProvider invalidOffers
     * @param array<string, string> $fields
     */
    public function testAnOfferIsValidatedWithTheRestOfTheForm(array $fields, string $field, string $key): void
    {
        try {
            $this->subscriptions->create(
                $this->alice,
                $this->input('Spotify', '5.99', ['is_promotional' => '1'] + $fields),
            );
            self::fail('The offer was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame($key, $exception->errors()[$field]->key ?? null);
        }

        self::assertSame([], $this->subscriptions->allForStats($this->alice, activeOnly: false));
    }

    public function testThePriceChangeFormSchedulesAnOfferAndItsEnd(): void
    {
        $id = $this->subscriptions->create($this->alice, $this->input('Spotify', '11.99'));

        $this->prices->schedule($this->alice, $id, [
            'price' => '5.99',
            'effective_from' => '2026-10-01',
            'is_promotional' => '1',
            'offer_ends_on' => '2027-01-01',
            // Blank: the price it goes back to is today's.
            'offer_then_price' => '',
        ]);

        $rows = $this->prices->historyFor($this->alice, $id);
        self::assertCount(3, $rows);
        self::assertSame([599, true], [$rows[1]->price->amountMinor, $rows[1]->isPromotional]);
        self::assertSame([1199, false, '2027-01-01'], [
            $rows[2]->price->amountMinor,
            $rows[2]->isPromotional,
            $rows[2]->effectiveFrom->format('Y-m-d'),
        ]);
        self::assertTrue($rows[2]->endsOfferFrom($rows[1]));
    }

    public function testAnOfferEndingIsListedInsteadOfARiseNeverBesideOne(): void
    {
        $this->createOffer('Spotify', '5.99', '2026-12-01', '11.99');
        $netflix = $this->subscriptions->create($this->alice, $this->input('Netflix', '10.99'));
        $this->prices->schedule($this->alice, $netflix, ['price' => '12.99', 'effective_from' => '2026-11-01']);

        $kinds = $this->kinds();

        self::assertSame([InsightKind::PromoEnding], $kinds['Spotify']);
        self::assertSame([InsightKind::PriceRising], $kinds['Netflix']);

        $ending = $this->insightFor('Spotify', InsightKind::PromoEnding);
        self::assertSame(600, $ending['difference_minor']);
        self::assertSame(1199, $ending['then']?->amountMinor);
        self::assertSame('2026-12-01', $ending['date']?->format('Y-m-d'));
        // £6 a month is £72 a year.
        self::assertSame(7200, $ending['annual_minor']);

        // Both rise on the dashboard's banner: the soonest is Netflix.
        $banner = $this->insights->nextScheduledRise($this->alice, $this->subscriptions->allForStats($this->alice));
        self::assertSame('Netflix', $banner['subscription']->name ?? null);
        self::assertFalse($banner['ends_offer'] ?? true);
    }

    public function testAnOfferThatHasEndedIsNotReportedAsARise(): void
    {
        $this->createOffer('Spotify', '5.99', '2026-10-01', '11.99');
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-02 09:00:00'));
        $this->prices->applyDueChanges($this->alice);

        self::assertArrayNotHasKey('Spotify', $this->kinds());
    }

    public function testAnIntroPriceWithNoEndAsksForOne(): void
    {
        $id = $this->subscriptions->create($this->alice, $this->input('Spotify', '5.99', ['is_promotional' => '1']));

        self::assertSame([InsightKind::PromoNoEnd], $this->kinds()['Spotify']);
        $promotion = $this->prices->promotionFor($this->alice, $id);
        self::assertNotNull($promotion);
        self::assertNull($promotion['ends_on']);

        // Last of everything.
        self::assertSame(
            max(array_map(static fn (InsightKind $kind): int => $kind->rank(), InsightKind::cases())),
            InsightKind::PromoNoEnd->rank(),
        );
        self::assertSame(InsightKind::PriceRising->rank(), InsightKind::PromoEnding->rank());
    }

    public function testAPrivateOffersPromotionReachesItsPayerOnly(): void
    {
        $private = $this->subscriptions->create($this->alice, $this->input('Mine', '5.99', [
            'visibility' => 'payer',
            'is_promotional' => '1',
            'offer_ends_on' => '2026-12-01',
            'offer_then_price' => '11.99',
        ]));
        $shared = $this->createOffer('Shared', '5.99', '2026-12-01', '11.99');

        self::assertArrayHasKey($private, $this->prices->promotions($this->alice));
        self::assertArrayHasKey($shared, $this->prices->promotions($this->alice));

        self::assertArrayNotHasKey($private, $this->prices->promotions($this->bob));
        self::assertArrayHasKey($shared, $this->prices->promotions($this->bob));
        self::assertNull($this->prices->promotionFor($this->bob, $private));
    }

    public function testTheApiResourceAndTheExportCarryTheOffer(): void
    {
        $id = $this->createOffer('Spotify', '5.99', '2026-12-01', '11.99');
        $plain = $this->subscriptions->create($this->alice, $this->input('Plain', '9.99'));
        $today = $this->clock->today();

        $resource = Resource::subscription(
            $this->subscriptions->find($this->alice, $id) ?? throw new RuntimeException(),
            $today,
            $this->prices->promotionFor($this->alice, $id),
        );
        self::assertTrue($resource['price_is_promotional']);
        self::assertSame('2026-12-01', $resource['promo_ends_on']);
        self::assertSame(1199, $resource['promo_then_price_minor']);

        $resource = Resource::subscription(
            $this->subscriptions->find($this->alice, $plain) ?? throw new RuntimeException(),
            $today,
        );
        self::assertFalse($resource['price_is_promotional']);
        self::assertNull($resource['promo_ends_on']);

        $rows = [];
        $export = $this->container->get(SubscriptionExportService::class);
        $json = $export->json($this->alice, new SubscriptionFilter());
        foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR) as $row) {
            $rows[$row['name']] = $row;
        }
        self::assertSame(['yes', '2026-12-01', '11.99'], [
            $rows['Spotify']['intro price'],
            $rows['Spotify']['offer ends'],
            $rows['Spotify']['price after offer'],
        ]);
        self::assertSame(['no', '', ''], [
            $rows['Plain']['intro price'],
            $rows['Plain']['offer ends'],
            $rows['Plain']['price after offer'],
        ]);
    }

    private function createOffer(string $name, string $price, string $endsOn, string $then): int
    {
        return $this->subscriptions->create($this->alice, $this->input($name, $price, [
            'is_promotional' => '1',
            'offer_ends_on' => $endsOn,
            'offer_then_price' => $then,
        ]));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function input(string $name, string $price, array $overrides = []): array
    {
        return $overrides + [
            'name' => $name,
            'price' => $price,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-09-15',
            'start_date' => '2026-09-01',
        ];
    }

    /**
     * @return array<string, list<InsightKind>>
     */
    private function kinds(): array
    {
        $kinds = [];
        $all = $this->subscriptions->allForStats($this->alice);
        foreach ($this->insights->insights($this->alice, $all, []) as $insight) {
            $kinds[$insight['subscription']->name][] = $insight['kind'];
        }

        return $kinds;
    }

    /**
     * @return array<string, mixed>
     */
    private function insightFor(string $name, InsightKind $kind): array
    {
        $all = $this->subscriptions->allForStats($this->alice);
        foreach ($this->insights->insights($this->alice, $all, []) as $insight) {
            if ($insight['subscription']->name === $name && $insight['kind'] === $kind) {
                return $insight;
            }
        }

        self::fail(sprintf('No %s insight for %s.', $kind->value, $name));
    }

    /**
     * @return array<int, \App\Domain\Entity\Subscription>
     */
    private function byId(): array
    {
        $byId = [];
        foreach ($this->subscriptions->allForStats($this->alice) as $subscription) {
            $byId[$subscription->id] = $subscription;
        }

        return $byId;
    }

    private function container(): ContainerInterface
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings['database'] = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/config/container.php')($builder, $settings);
        $builder->addDefinitions([
            Clock::class => $this->clock,
            GuardedClient::class => factory(fn (): GuardedClient => FakeGuardedClient::returning('', 404)),
        ]);

        return $builder->build();
    }
}
