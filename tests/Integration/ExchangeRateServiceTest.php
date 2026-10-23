<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ExchangeRate;
use App\Domain\Money;
use App\Repository\ExchangeRateRepository;
use App\Repository\InstanceSettingsRepository;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRate\RateProviderException;
use App\Service\ExchangeRateService;
use App\Service\InstanceSettingsService;
use App\Support\FrozenClock;
use App\Tests\Support\FakeHttpClient;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use App\Tests\Support\TestKeys;

/**
 * Caching, cross-rating and — the part that matters most — what happens when
 * rates are not available.
 *
 * The degraded path is tested as carefully as the working one on purpose. An
 * application that quietly adds a hundred yen to a hundred pounds because a
 * feed was down has produced a wrong number and told nobody; returning null and
 * letting the caller show subtotals is the behaviour being protected here.
 */
final class ExchangeRateServiceTest extends DatabaseTestCase
{
    private const TTL_SECONDS = 43200;
    private const RETRY_SECONDS = 3600;

    private InstanceSettingsService $settings;
    private ExchangeRateRepository $rates;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        $this->rates = new ExchangeRateRepository($this->db);
        $this->clock = FrozenClock::at('2026-09-14 09:00:00');

        $this->settings->setBaseCurrency('GBP');
    }

    public function testRefreshCachesTheProvidersTable(): void
    {
        $service = $this->service($this->workingProvider());

        self::assertSame(4, $service->refresh());

        $cached = $this->rates->findAllForBase('GBP');
        self::assertSame(
            ['EUR', 'GBP', 'JPY', 'USD'],
            array_keys($cached),
        );
        self::assertSame(FrankfurterProvider::KEY, $cached['EUR']->provider);
        self::assertSame('2026-09-11', $cached['EUR']->asOfDate?->format('Y-m-d'));
    }

    public function testConvertsThroughTheCachedTable(): void
    {
        $service = $this->service($this->workingProvider());
        $service->refresh();

        $converted = $service->convert(Money::of(1000, 'GBP'), 'EUR');

        self::assertNotNull($converted);
        self::assertSame('EUR', $converted->currency);
        self::assertSame(1172, $converted->amountMinor);
    }

    public function testCrossRatesBetweenTwoNonBaseCurrencies(): void
    {
        // Only base-relative rates are stored, so EUR -> USD has to be derived:
        // (GBP -> USD) divided by (GBP -> EUR).
        $service = $this->service($this->workingProvider());
        $service->refresh();

        $rate = $service->rateFor('EUR', 'USD');

        self::assertNotNull($rate);
        // 1.2684 / 1.1723 = 1.08197560... to the eight places we keep.
        self::assertSame(ExchangeRate::scaleFromDecimalString('1.0819756'), $rate->rateScaled);
    }

    public function testAnUncachedCurrencyDegradesToNullRatherThanGuessing(): void
    {
        $service = $this->service($this->workingProvider());
        $service->refresh();

        // The ECB feed does not publish this one, so there is no honest answer.
        self::assertNull($service->rateFor('GBP', 'XOF'));
        self::assertNull($service->convert(Money::of(1000, 'XOF'), 'GBP'));
        self::assertNull($service->convertMinor(1000, 'XOF', 'GBP'));
    }

    public function testCombiningRefusesWhenAnySingleCurrencyIsMissing(): void
    {
        $service = $this->service($this->workingProvider());
        $service->refresh();

        self::assertTrue($service->canCombine(['GBP', 'EUR', 'USD'], 'GBP'));
        self::assertSame(2000, $service->combine(['GBP' => 1000, 'EUR' => 1172], 'GBP'));

        // One unknown currency makes the whole total unreportable. A partial
        // total with no indication of what it left out is a wrong number, not
        // an approximate one.
        self::assertFalse($service->canCombine(['GBP', 'XOF'], 'GBP'));
        self::assertNull($service->combine(['GBP' => 1000, 'XOF' => 5000], 'GBP'));
    }

    public function testConversionToTheSameCurrencyNeedsNoCachedRateAtAll(): void
    {
        $service = $this->service($this->workingProvider());

        self::assertSame(1000, $service->convertMinor(1000, 'GBP', 'GBP'));
        self::assertTrue($service->canCombine(['JPY'], 'JPY'));
    }

    public function testAFailingProviderLeavesThePreviousTableInPlace(): void
    {
        $working = $this->service($this->workingProvider());
        $working->refresh();

        // Time passes, the cache goes stale, and now the provider is down.
        $this->clock->advanceTo($this->clock->now()->modify('+2 days'));
        $broken = $this->service(new FrankfurterProvider(FakeHttpClient::failing(), new RequestFactory()));

        self::assertTrue($broken->isStale());

        // Best-effort: it must not throw, and it must not empty the cache.
        $broken->refreshIfStale();

        self::assertSame(1172, $broken->convertMinor(1000, 'GBP', 'EUR'));
    }

    public function testAFailedAttemptIsNotRetriedOnEveryPageView(): void
    {
        $http = FakeHttpClient::failing();
        $service = $this->service(new FrankfurterProvider($http, new RequestFactory()));

        $service->refreshIfStale();
        $service->refreshIfStale();
        $service->refreshIfStale();

        // One attempt, not three: a provider outage must not turn every request
        // into a synchronous network call that is going to fail anyway.
        self::assertCount(1, $http->requestedUrls);

        // Once the retry window has passed, it tries again.
        $this->clock->advanceTo($this->clock->now()->modify('+' . (self::RETRY_SECONDS + 60) . ' seconds'));
        $this->service(new FrankfurterProvider($http, new RequestFactory()))->refreshIfStale();

        self::assertCount(2, $http->requestedUrls);
    }

    /**
     * Settings' Refresh now (Phase 28) is a person asking, and it goes through
     * the provider and the shared HTTP client like the scheduled refresh — the
     * fake client here is that client. What it may not do is click through the
     * back-off a failed attempt set.
     */
    public function testRefreshNowIsRefusedWhileAFailedAttemptIsBackingOff(): void
    {
        $http = FakeHttpClient::failing();
        $service = $this->service(new FrankfurterProvider($http, new RequestFactory()));

        try {
            $service->refreshNow();
            self::fail('A failing provider should have thrown.');
        } catch (RateProviderException) {
            // The failure is the caller's to report; the page flashes it.
        }
        self::assertCount(1, $http->requestedUrls);

        $until = $service->retryAfter();
        self::assertNotNull($until);
        self::assertSame('2026-09-14 10:00:00', $until->format('Y-m-d H:i:s'));

        // Inside the window: refused, and nothing is sent.
        self::assertNull($service->refreshNow());
        self::assertCount(1, $http->requestedUrls);

        // Once it has passed, the button works again.
        $this->clock->advanceTo($this->clock->now()->modify('+' . (self::RETRY_SECONDS + 1) . ' seconds'));
        self::assertNull($service->retryAfter());
        $this->expectException(RateProviderException::class);
        $service->refreshNow();
    }

    public function testRefreshNowIsNotHeldBackByASuccessfulRefresh(): void
    {
        $http = $this->workingResponse();
        $service = $this->service(new FrankfurterProvider($http, new RequestFactory()));

        self::assertSame(4, $service->refreshNow());
        self::assertNull($service->retryAfter());

        // Asking again straight away is what the button is for.
        self::assertSame(4, $service->refreshNow());
        self::assertCount(2, $http->requestedUrls);
    }

    /**
     * Changing the base or the provider drops the table. That must drop the
     * attempt marker with it, or the successful refresh a moment earlier would
     * look like a failure — an attempt newer than any table — and lock Refresh
     * now out for an hour just when the administrator wants it.
     */
    public function testChangingTheBaseDoesNotTurnAnEarlierSuccessIntoABackOff(): void
    {
        $http = $this->workingResponse();
        $service = $this->service(new FrankfurterProvider($http, new RequestFactory()));
        $service->refresh();

        $this->settings->setBaseCurrency('EUR');
        $service->invalidate();

        self::assertNull($service->retryAfter());
        $service->refreshNow();
        self::assertCount(2, $http->requestedUrls, 'the refresh was sent, not refused');
    }

    public function testTheRateTableIsListedAgainstTheBaseWithoutTheBaseItself(): void
    {
        $service = $this->service($this->workingProvider());
        $service->refresh();

        self::assertSame(
            ['EUR', 'JPY', 'USD'],
            array_map(static fn (ExchangeRate $rate): string => $rate->quoteCurrency, $service->rates()),
        );
    }

    public function testAFreshCacheIsNotRefetched(): void
    {
        $http = $this->workingResponse();
        $service = $this->service(new FrankfurterProvider($http, new RequestFactory()));

        $service->refresh();
        self::assertFalse($service->isStale());

        $service->refreshIfStale();
        self::assertCount(1, $http->requestedUrls);
    }

    public function testTheCacheGoesStaleOnceTheTtlHasPassed(): void
    {
        $service = $this->service($this->workingProvider());
        $service->refresh();

        $this->clock->advanceTo($this->clock->now()->modify('+' . (self::TTL_SECONDS - 60) . ' seconds'));
        self::assertFalse($service->isStale());

        $this->clock->advanceTo($this->clock->now()->modify('+120 seconds'));
        self::assertTrue($service->isStale());
    }

    public function testRefreshReplacesRatherThanAccumulates(): void
    {
        $service = $this->service($this->workingProvider());
        $service->refresh();

        // The provider stops publishing JPY. It must disappear from the cache
        // rather than linger at whatever it was worth the last time we looked.
        $shrunk = new FrankfurterProvider(
            FakeHttpClient::returningJson([
                'base' => 'GBP',
                'date' => '2026-09-12',
                'rates' => ['EUR' => 1.20],
            ]),
            new RequestFactory(),
        );

        $this->service($shrunk)->refresh();

        $cached = $this->rates->findAllForBase('GBP');
        self::assertSame(['EUR', 'GBP'], array_keys($cached));
        self::assertNull($this->service($shrunk)->rateFor('GBP', 'JPY'));
    }

    public function testAProviderThatPublishesADifferentBaseIsRebased(): void
    {
        // Fixer's free tier only serves EUR. The instance's base is GBP, so the
        // table has to be re-expressed before it is stored, or every number
        // cached would be against the wrong base.
        $euroOnly = new FrankfurterProvider(
            FakeHttpClient::returningJson([
                'base' => 'EUR',
                'date' => '2026-09-11',
                'rates' => ['GBP' => 0.853, 'USD' => 1.0819],
            ]),
            new RequestFactory(),
        );

        $service = $this->service($euroOnly);
        $service->refresh();

        $cached = $this->rates->findAllForBase('GBP');
        self::assertArrayHasKey('EUR', $cached);
        self::assertSame(ExchangeRate::SCALE, $cached['GBP']->rateScaled);
        self::assertEqualsWithDelta(
            ExchangeRate::scaleFromDecimalString('1.17233'),
            $cached['EUR']->rateScaled,
            1000,
        );
    }

    public function testInvalidatingDropsEverything(): void
    {
        $service = $this->service($this->workingProvider());
        $service->refresh();

        $service->invalidate();

        self::assertSame([], $this->rates->findAllForBase('GBP'));
        self::assertNull($service->rateFor('GBP', 'EUR'));
        self::assertTrue($service->isStale());
    }

    public function testAProviderNeedingAKeyWithoutOneIsReportedAsMisconfigured(): void
    {
        $registry = new ExchangeRateProviderRegistry([
            new \App\Service\ExchangeRate\FixerProvider(FakeHttpClient::returning('{}'), new RequestFactory()),
        ]);

        $service = new ExchangeRateService(
            $this->rates,
            $registry,
            $this->settings,
            $this->clock,
            new NullLogger(),
            self::TTL_SECONDS,
            self::RETRY_SECONDS,
            '',
        );

        self::assertTrue($service->isMisconfigured());
    }

    public function testAStoredKeyIsSentDecrypted(): void
    {
        $this->settings->setRateProviderKey('key-from-the-database');

        $http = FakeHttpClient::returningJson([
            'success' => true,
            'source' => 'GBP',
            'quotes' => ['GBPEUR' => 1.1723],
        ]);

        $service = new ExchangeRateService(
            $this->rates,
            new ExchangeRateProviderRegistry([
                new \App\Service\ExchangeRate\ExchangeRateHostProvider($http, new RequestFactory()),
            ]),
            $this->settings,
            $this->clock,
            new NullLogger(),
            self::TTL_SECONDS,
            self::RETRY_SECONDS,
            '',
        );

        $service->refresh();

        // Stored encrypted, sent as entered.
        self::assertStringContainsString('access_key=key-from-the-database', $http->requestedUrls[0]);
    }

    public function testAnEnvironmentKeyIsUsedInPreferenceToAStoredOne(): void
    {
        $this->settings->setRateProviderKey('key-from-the-database');

        $http = FakeHttpClient::returningJson([
            'success' => true,
            'source' => 'GBP',
            'quotes' => ['GBPEUR' => 1.1723],
        ]);

        $registry = new ExchangeRateProviderRegistry([
            new \App\Service\ExchangeRate\ExchangeRateHostProvider($http, new RequestFactory()),
        ]);

        $service = new ExchangeRateService(
            $this->rates,
            $registry,
            $this->settings,
            $this->clock,
            new NullLogger(),
            self::TTL_SECONDS,
            self::RETRY_SECONDS,
            'key-from-the-environment',
        );

        $service->refresh();

        // The operator who put the key in their environment said they did not
        // want it in the database. Clicking about in settings must not override
        // that.
        self::assertStringContainsString('access_key=key-from-the-environment', $http->requestedUrls[0]);
        self::assertFalse($service->isMisconfigured());
    }

    private function workingResponse(): FakeHttpClient
    {
        return FakeHttpClient::returningJson([
            'amount' => 1.0,
            'base' => 'GBP',
            'date' => '2026-09-11',
            'rates' => ['EUR' => 1.1723, 'USD' => 1.2684, 'JPY' => 190.5],
        ]);
    }

    private function workingProvider(): FrankfurterProvider
    {
        return new FrankfurterProvider($this->workingResponse(), new RequestFactory());
    }

    private function service(FrankfurterProvider $provider): ExchangeRateService
    {
        return new ExchangeRateService(
            $this->rates,
            new ExchangeRateProviderRegistry([$provider]),
            $this->settings,
            $this->clock,
            new NullLogger(),
            self::TTL_SECONDS,
            self::RETRY_SECONDS,
            '',
        );
    }
}
