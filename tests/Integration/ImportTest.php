<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Api\Resource;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Http\GuardedClient;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\ImportService;
use App\Service\Import\ImportPreset;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\FakeGuardedClient;
use App\Tests\Support\FakeUpload;
use DateTimeImmutable;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

use function DI\autowire;
use function DI\factory;

/**
 * Importing a file: what the preview promises is what the commit does.
 *
 * The round trip is the strongest claim available — export what this
 * application produced, feed it back in, and get the same subscriptions. It is
 * the one check that cannot pass by accident, because it exercises the
 * representation, the parser, the column guesser and the translator together.
 */
final class ImportTest extends DatabaseTestCase
{
    private ImportService $imports;
    private SubscriptionService $subscriptions;
    private Scope $scope;
    private \App\Domain\Entity\User $user;
    private string $directory;
    private FrozenClock $clock;
    private FakeGuardedClient $logoRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logoRequests = FakeGuardedClient::returning('', 404);
        $this->directory = sys_get_temp_dir() . '/renovo-imports-' . bin2hex(random_bytes(6));
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-06-01 09:00:00'));

        // Built from the real container rather than by hand. Wiring these
        // services manually would mean this test kept passing after a
        // constructor changed, which is the opposite of what it is for.
        $container = $this->container();
        $this->imports = $container->get(ImportService::class);
        $this->subscriptions = $container->get(SubscriptionService::class);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $userId = $users->create('owner@example.test', 'Owner', 'hash', false, $this->clock->now());
        $householdId = $households->create('Household', $userId);
        $memberships->create($householdId, $userId, Role::OwnerAdmin);

        $this->user = $users->findById($userId);
        $this->scope = Scope::forMember($userId, false, $householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    /**
     * The application's own container, with the clock frozen and the staging
     * directory pointed somewhere disposable.
     */
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
            // The network stands still for this test, and one of the tests
            // below asserts that nothing reached for it.
            GuardedClient::class => factory(fn (): GuardedClient => $this->logoRequests),
            ImportService::class => autowire(ImportService::class)
                ->constructorParameter('directory', factory(fn (): string => $this->directory)),
        ]);

        return $builder->build();
    }

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function testACsvIsParsedMappedPreviewedAndCommitted(): void
    {
        $csv = <<<CSV
        Name,Price,Currency,Billing cycle,Next payment date,Category,Tags,Notes
        Streaming,10.99,GBP,Monthly,2026-12-01,Entertainment,"video, family",Shared with the house
        Broadband,35.00,GBP,Monthly,2026-12-15,Utilities,,
        Insurance,240.00,GBP,Yearly,2027-03-01,Insurance,car,
        CSV;

        $id = $this->imports->stage(FakeUpload::of($csv, 'export.csv'));

        $file = $this->imports->read($id);
        self::assertCount(3, $file->rows);

        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $file->headers);

        // The automatic preset should find all of these without help.
        foreach (['name', 'price', 'currency', 'billing_cycle', 'next_payment_date', 'category', 'tags'] as $field) {
            self::assertArrayHasKey($field, $mapping, sprintf('The preset failed to map "%s".', $field));
        }

        $preview = $this->imports->preview($this->scope, $id, $mapping, 'GBP');
        self::assertCount(3, $preview);

        foreach ($preview as $row) {
            self::assertSame([], $row['errors'], sprintf('Row %d should be valid.', $row['row']));
        }

        $result = $this->imports->commit($this->scope, $this->user, $id, $mapping, 'GBP');

        self::assertSame(['imported' => 3, 'skipped' => 0], $result);

        $imported = $this->subscriptions->allForStats($this->scope, activeOnly: false);
        self::assertCount(3, $imported);

        $byName = [];
        foreach ($imported as $subscription) {
            $byName[$subscription->name] = $subscription;
        }

        // Money arrives as an integer, via Money::fromUserInput. No float
        // touches it at any point.
        self::assertSame(1099, $byName['Streaming']->price->amountMinor);
        self::assertSame(3500, $byName['Broadband']->price->amountMinor);
        self::assertSame(24000, $byName['Insurance']->price->amountMinor);

        self::assertSame('monthly', $byName['Streaming']->billingCycle?->value);
        self::assertSame('yearly', $byName['Insurance']->billingCycle?->value);
        self::assertSame('2026-12-01', $byName['Streaming']->nextPaymentDate?->format('Y-m-d'));

        self::assertSame('Entertainment', $byName['Streaming']->categoryName);
        self::assertEqualsCanonicalizing(
            ['video', 'family'],
            array_map(static fn ($tag): string => $tag->name, $byName['Streaming']->tags),
        );
    }

    public function testACategoryAndATagAreMatchedWholeIgnoringCase(): void
    {
        $categories = new \App\Repository\CategoryRepository($this->db);
        $longer = $categories->create($this->scope, 'Music Videos', null);
        $music = $categories->create($this->scope, 'Music', null);
        $tags = new \App\Repository\TagRepository($this->db);
        $tags->create($this->scope, 'Apple TV');

        $csv = <<<CSV
        Name,Price,Currency,Billing cycle,Next payment date,Category,Tags
        One,1.00,GBP,Monthly,2026-12-01,music,TV
        Two,2.00,GBP,Monthly,2026-12-01,MUSIC VIDEOS,tv
        Three,3.00,GBP,Monthly,2026-12-01,Music Vid,
        CSV;
        $id = $this->imports->stage(FakeUpload::of($csv, 'export.csv'));
        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $this->imports->read($id)->headers);

        $result = $this->imports->commit($this->scope, $this->user, $id, $mapping, 'GBP');

        self::assertSame(['imported' => 3, 'skipped' => 0], $result);
        $byName = [];
        foreach ($this->subscriptions->allForStats($this->scope, activeOnly: false) as $subscription) {
            $byName[$subscription->name] = $subscription;
        }
        self::assertSame($music, $byName['One']->categoryId);
        self::assertSame($longer, $byName['Two']->categoryId);
        self::assertNotContains($byName['Three']->categoryId, [$music, $longer]);
        self::assertCount(3, $categories->findAll($this->scope));
        self::assertSame(['TV'], array_map(static fn ($tag): string => $tag->name, $byName['One']->tags));
        self::assertCount(2, $tags->findAll($this->scope));
    }

    public function testAPlanColumnIsMappedAndImported(): void
    {
        $csv = <<<CSV
        Name,Price,Currency,Billing cycle,Next payment date,Plan
        Streaming,17.99,GBP,Monthly,2026-12-01,Premium
        Music,10.99,GBP,Monthly,2026-12-01,
        CSV;

        $id = $this->imports->stage(FakeUpload::of($csv, 'plans.csv'));
        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $this->imports->read($id)->headers);
        self::assertArrayHasKey('plan', $mapping);

        $this->imports->commit($this->scope, $this->user, $id, $mapping, 'GBP');

        $plans = [];
        foreach ($this->subscriptions->allForStats($this->scope, activeOnly: false) as $subscription) {
            $plans[$subscription->name] = $subscription->plan;
        }
        self::assertSame(['Music' => null, 'Streaming' => 'Premium'], $this->sorted($plans));
    }

    /**
     * @param array<string, string|null> $values
     * @return array<string, string|null>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    /**
     * Export, import, compare. The strongest statement the importer can make.
     */
    public function testWhatThisApplicationExportsItCanImport(): void
    {
        $this->subscriptions->create($this->scope, [
            'name' => 'Round trip',
            'price' => '12.34',
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'quarterly',
            'next_payment_date' => '2026-09-30',
            'start_date' => '2024-01-15',
            'notes' => 'Carried across',
            'tags' => 'alpha,beta',
        ]);

        $original = $this->subscriptions->allForStats($this->scope, activeOnly: false)[0];

        // The same representation the API serves and the backup writes.
        $export = json_encode(
            ['subscriptions' => [Resource::subscription($original, $this->clock->today())]],
            JSON_THROW_ON_ERROR,
        );

        // A second household, so the import lands somewhere clean.
        $target = $this->secondHousehold();

        $id = $this->imports->stage(FakeUpload::of($export, 'renovo-export.json'));
        $file = $this->imports->read($id);

        $mapping = $this->imports->suggestMapping('renovo', $file->headers);
        $result = $this->imports->commit($target, $this->user, $id, $mapping, 'GBP');

        self::assertSame(1, $result['imported']);

        $imported = $this->subscriptions->allForStats($target, activeOnly: false)[0];

        self::assertSame($original->name, $imported->name);
        self::assertSame($original->price->amountMinor, $imported->price->amountMinor);
        self::assertSame($original->price->currency, $imported->price->currency);
        self::assertSame($original->billingCycle?->value, $imported->billingCycle?->value);
        self::assertSame(
            $original->nextPaymentDate?->format('Y-m-d'),
            $imported->nextPaymentDate?->format('Y-m-d'),
        );
        self::assertSame($original->startDate?->format('Y-m-d'), $imported->startDate?->format('Y-m-d'));
        self::assertSame($original->notes, $imported->notes);
        self::assertEqualsCanonicalizing(
            array_map(static fn ($tag): string => $tag->name, $original->tags),
            array_map(static fn ($tag): string => $tag->name, $imported->tags),
        );
    }

    /**
     * Settings' Export section (Phase 28) says the importer reads either of
     * its files back. This is that claim, for each: the list's export, staged
     * as it downloads, mapped by the automatic preset with no help, and
     * committed into another household.
     *
     * @return array<string, array{string}>
     */
    public static function exportFormats(): array
    {
        return ['csv' => ['csv'], 'json' => ['json']];
    }

    /**
     * @dataProvider exportFormats
     */
    public function testTheListExportIsReadBackByTheAutomaticPreset(string $format): void
    {
        $this->subscriptions->create($this->scope, [
            'name' => 'Round trip',
            'price' => '12.34',
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'quarterly',
            'next_payment_date' => '2026-09-30',
            'tags' => 'alpha,beta',
        ]);

        $export = $this->container()->get(\App\Service\SubscriptionExportService::class);
        $filter = \App\Domain\SubscriptionFilter::fromQueryParams([])->withIncludeInactive();
        $contents = $format === 'csv' ? $export->csv($this->scope, $filter) : $export->json($this->scope, $filter);

        $id = $this->imports->stage(FakeUpload::of($contents, 'subscriptions.' . $format));
        $file = $this->imports->read($id);
        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $file->headers);

        $target = $this->secondHousehold();
        $result = $this->imports->commit($target, $this->user, $id, $mapping, 'GBP');
        self::assertSame(['imported' => 1, 'skipped' => 0], $result);

        $imported = $this->subscriptions->allForStats($target, activeOnly: false)[0];
        self::assertSame('Round trip', $imported->name);
        self::assertSame(1234, $imported->price->amountMinor);
        self::assertSame('EUR', $imported->price->currency);
        self::assertSame('quarterly', $imported->billingCycle?->value);
        self::assertSame('2026-09-30', $imported->nextPaymentDate?->format('Y-m-d'));
        self::assertEqualsCanonicalizing(
            ['alpha', 'beta'],
            array_map(static fn ($tag): string => $tag->name, $imported->tags),
        );
    }

    /**
     * The interval survives each of the three ways out and back in: the
     * API's representation, and the list's CSV and JSON.
     *
     * @return array<string, array{string}>
     */
    public static function intervalRoundTrips(): array
    {
        return ['renovo' => ['renovo'], 'csv' => ['csv'], 'json' => ['json']];
    }

    /**
     * @dataProvider intervalRoundTrips
     */
    public function testTheIntervalRoundTripsThroughEveryExport(string $format): void
    {
        $this->subscriptions->create($this->scope, [
            'name' => 'Car insurance',
            'price' => '180.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'cycle_interval' => '6',
            'next_payment_date' => '2026-08-31',
        ]);

        if ($format === 'renovo') {
            $original = $this->subscriptions->allForStats($this->scope, activeOnly: false)[0];
            $contents = json_encode(
                ['subscriptions' => [Resource::subscription($original, $this->clock->today())]],
                JSON_THROW_ON_ERROR,
            );
            $preset = 'renovo';
            $name = 'renovo-export.json';
        } else {
            $export = $this->container()->get(\App\Service\SubscriptionExportService::class);
            $filter = \App\Domain\SubscriptionFilter::fromQueryParams([])->withIncludeInactive();
            $contents = $format === 'csv' ? $export->csv($this->scope, $filter) : $export->json($this->scope, $filter);
            $preset = ImportPreset::AUTOMATIC;
            $name = 'subscriptions.' . $format;
        }

        $id = $this->imports->stage(FakeUpload::of($contents, $name));
        $file = $this->imports->read($id);
        $mapping = $this->imports->suggestMapping($preset, $file->headers);
        self::assertArrayHasKey('cycle_interval', $mapping);

        $target = $this->secondHousehold();
        $result = $this->imports->commit($target, $this->user, $id, $mapping, 'GBP');
        self::assertSame(['imported' => 1, 'skipped' => 0], $result);

        $imported = $this->subscriptions->allForStats($target, activeOnly: false)[0];
        self::assertSame('monthly', $imported->billingCycle?->value);
        self::assertSame(6, $imported->cycleInterval);
        self::assertSame(36000, $imported->yearlyMinor());
    }

    /**
     * @dataProvider intervalRoundTrips
     */
    public function testTheTermsRoundTripThroughEveryExport(string $format): void
    {
        $this->subscriptions->create($this->scope, [
            'name' => 'Energy',
            'price' => '58.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-08-31',
            'contract_ends_on' => '2027-03-14',
            'payments_end_on' => '2028-11-01',
            'amount_varies' => '1',
        ]);

        if ($format === 'renovo') {
            $original = $this->subscriptions->allForStats($this->scope, activeOnly: false)[0];
            $contents = json_encode(
                ['subscriptions' => [Resource::subscription($original, $this->clock->today())]],
                JSON_THROW_ON_ERROR,
            );
            $preset = 'renovo';
            $name = 'renovo-export.json';
        } else {
            $export = $this->container()->get(\App\Service\SubscriptionExportService::class);
            $filter = \App\Domain\SubscriptionFilter::fromQueryParams([])->withIncludeInactive();
            $contents = $format === 'csv' ? $export->csv($this->scope, $filter) : $export->json($this->scope, $filter);
            $preset = ImportPreset::AUTOMATIC;
            $name = 'subscriptions.' . $format;
        }

        $id = $this->imports->stage(FakeUpload::of($contents, $name));
        $mapping = $this->imports->suggestMapping($preset, $this->imports->read($id)->headers);

        $target = $this->secondHousehold();
        self::assertSame(
            ['imported' => 1, 'skipped' => 0],
            $this->imports->commit($target, $this->user, $id, $mapping, 'GBP'),
        );

        $imported = $this->subscriptions->allForStats($target, activeOnly: false)[0];
        self::assertSame('2027-03-14', $imported->contractEndsOn?->format('Y-m-d'));
        self::assertSame('2028-11-01', $imported->paymentsEndOn?->format('Y-m-d'));
        self::assertTrue($imported->amountVaries);
    }

    public function testAnImpossibleDateIsARefusedRowNotAnotherDay(): void
    {
        $csv = <<<CSV
        Name,Price,Currency,Billing cycle,Next payment date
        Broken,10.00,GBP,Monthly,31/02/2026
        Fine,10.00,GBP,Monthly,28/02/2027
        CSV;

        $id = $this->imports->stage(FakeUpload::of($csv, 'dates.csv'));
        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $this->imports->read($id)->headers);

        $target = $this->secondHousehold();
        $result = $this->imports->commit($target, $this->user, $id, $mapping, 'GBP');

        self::assertSame(['imported' => 1, 'skipped' => 1], $result);
        self::assertSame(['Fine'], array_map(
            static fn ($row): string => $row->name,
            $this->subscriptions->allForStats($target, activeOnly: false),
        ));
    }

    public function testSixMonthsImportsAsSixMonthsNotSixDays(): void
    {
        $csv = <<<CSV
        Name,Price,Currency,Billing cycle,Next payment date
        Car insurance,180.00,GBP,6 months,2026-08-31
        Membership,50.00,GBP,2 years,2028-02-29
        Cleaner,40.00,GBP,Fortnightly,2026-06-05
        Water,45.00,GBP,45 days,2026-06-20
        Mystery,5.00,GBP,whenever,2026-06-20
        CSV;

        $id = $this->imports->stage(FakeUpload::of($csv, 'cycles.csv'));
        $file = $this->imports->read($id);
        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $file->headers);

        // The preview shows the guess before anything is written, including
        // the fallback to monthly for a value nothing recognised.
        $preview = $this->imports->preview($this->scope, $id, $mapping, 'GBP');
        $mystery = array_values(array_filter(
            $preview,
            static fn (array $row): bool => $row['input']['name'] === 'Mystery',
        ));
        self::assertSame('monthly', $mystery[0]['input']['billing_cycle']);

        $this->imports->commit($this->scope, $this->user, $id, $mapping, 'GBP');

        $byName = [];
        foreach ($this->subscriptions->allForStats($this->scope, activeOnly: false) as $subscription) {
            $byName[$subscription->name] = [
                $subscription->billingCycle?->value,
                $subscription->cycleInterval,
                $subscription->cycleDays,
            ];
        }

        self::assertSame(['monthly', 6, null], $byName['Car insurance']);
        self::assertSame(['yearly', 2, null], $byName['Membership']);
        self::assertSame(['weekly', 2, null], $byName['Cleaner']);
        self::assertSame(['custom_days', 1, 45], $byName['Water']);
        self::assertSame(['monthly', 1, null], $byName['Mystery']);
    }

    public function testAnInvalidRowIsReportedInThePreviewAndSkippedOnCommit(): void
    {
        $csv = <<<CSV
        Name,Price,Currency,Billing cycle,Next payment date
        Good,9.99,GBP,Monthly,2026-12-01
        ,5.00,GBP,Monthly,2026-12-01
        Also good,1.00,GBP,Monthly,2026-12-02
        CSV;

        $id = $this->imports->stage(FakeUpload::of($csv, 'mixed.csv'));
        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $this->imports->read($id)->headers);

        $preview = $this->imports->preview($this->scope, $id, $mapping, 'GBP');

        self::assertSame([], $preview[0]['errors']);
        self::assertArrayHasKey('name', $preview[1]['errors']);
        self::assertSame([], $preview[2]['errors']);

        $result = $this->imports->commit($this->scope, $this->user, $id, $mapping, 'GBP');

        self::assertSame(['imported' => 2, 'skipped' => 1], $result);
    }

    public function testAPriceAboveTheCeilingIsReportedInThePreviewAndSkippedOnCommit(): void
    {
        $csv = <<<CSV
        Name,Price,Currency,Billing cycle,Next payment date
        Fine,9.99,GBP,Monthly,2026-12-01
        Huge,99999999999999999999,GBP,Monthly,2026-12-01
        Just over,10000000000.01,GBP,Weekly,2026-12-01
        At the ceiling,10000000000.00,GBP,Yearly,2026-12-01
        CSV;

        $id = $this->imports->stage(FakeUpload::of($csv, 'huge.csv'));
        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $this->imports->read($id)->headers);

        $preview = $this->imports->preview($this->scope, $id, $mapping, 'GBP');

        self::assertSame([], $preview[0]['errors']);
        self::assertSame('error.price.too_large', $preview[1]['errors']['price']->key);
        self::assertSame('error.price.too_large', $preview[2]['errors']['price']->key);
        self::assertSame([], $preview[3]['errors']);

        $result = $this->imports->commit($this->scope, $this->user, $id, $mapping, 'GBP');

        self::assertSame(['imported' => 2, 'skipped' => 2], $result);
    }

    public function testThePreviewWritesNothingAtAll(): void
    {
        // Including tags, which the validation path would otherwise create —
        // a preview that invented forty tags the user then abandoned would be
        // leaving litter behind.
        $csv = <<<CSV
        Name,Price,Currency,Billing cycle,Next payment date,Tags
        Streaming,9.99,GBP,Monthly,2026-12-01,"invented, tags"
        CSV;

        $id = $this->imports->stage(FakeUpload::of($csv, 'preview.csv'));
        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $this->imports->read($id)->headers);

        $this->imports->preview($this->scope, $id, $mapping, 'GBP');

        self::assertSame([], $this->subscriptions->allForStats($this->scope, activeOnly: false));
        self::assertSame([], (new TagRepository($this->db))->findAll($this->scope));
    }

    public function testSemicolonSeparatedFilesAndAByteOrderMarkAreHandled(): void
    {
        // What a European Excel export actually looks like.
        $csv = "\xEF\xBB\xBFName;Price;Currency;Billing cycle;Next payment date\r\n"
            . "Streaming;10,99;EUR;Monthly;2026-12-01\r\n";

        $id = $this->imports->stage(FakeUpload::of($csv, 'excel.csv'));
        $file = $this->imports->read($id);

        self::assertSame(['Name', 'Price', 'Currency', 'Billing cycle', 'Next payment date'], $file->headers);

        $mapping = $this->imports->suggestMapping(ImportPreset::AUTOMATIC, $file->headers);
        $this->imports->commit($this->scope, $this->user, $id, $mapping, 'GBP');

        $imported = $this->subscriptions->allForStats($this->scope, activeOnly: false)[0];

        self::assertSame('Streaming', $imported->name);
        self::assertSame(1099, $imported->price->amountMinor);
        self::assertSame('EUR', $imported->price->currency);
    }

    public function testCommittingClearsTheStagedFile(): void
    {
        $id = $this->imports->stage(FakeUpload::of("Name,Price\nStreaming,9.99\n", 'small.csv'));

        $this->imports->commit($this->scope, $this->user, $id, ['name' => 'Name', 'price' => 'Price'], 'GBP');

        $this->expectException(ValidationException::class);
        $this->imports->read($id);
    }

    public function testAStagingIdThatIsNotOneOfOursIsRefused(): void
    {
        // The id comes from the session, but it is still a string reaching the
        // filesystem, and "../" is the obvious thing to try.
        foreach (['../../etc/passwd', 'not-hex', '', str_repeat('z', 32)] as $id) {
            $refused = false;

            try {
                $this->imports->read($id);
            } catch (ValidationException) {
                $refused = true;
            }

            self::assertTrue($refused, sprintf('Reading "%s" should have been refused.', $id));
        }
    }

    private function secondHousehold(): Scope
    {
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $userId = $users->create('second@example.test', 'Second', 'hash', false, $this->clock->now());
        $householdId = $households->create('Second household', $userId);
        $memberships->create($householdId, $userId, Role::OwnerAdmin);

        return Scope::forMember($userId, false, $householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach ((array) glob($directory . '/*') as $path) {
            if (is_string($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    /**
     * A preview is a dry run, and a dry run must not reach for the network.
     *
     * `validate()` is shared by the real write and by the preview that tells a
     * user which of four hundred rows would fail. It creates tags and fetches
     * logos on the way through, and doing either for a row nobody has agreed
     * to import yet would be a page load with consequences — four hundred
     * outbound requests among them.
     */
    public function testAPreviewFetchesNoLogosAndCreatesNoTags(): void
    {
        $tagsBefore = (int) $this->db->fetchValue('SELECT COUNT(*) FROM tags');

        $errors = $this->subscriptions->validationErrors($this->scope, [
            'name' => 'Something',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'website_url' => 'https://example.com',
            'tags' => 'a-tag-that-did-not-exist',
        ]);

        self::assertSame([], $errors, 'The row itself is valid.');

        self::assertSame(
            [],
            $this->logoRequests->requests,
            'A preview must make no outbound request, however many websites the file names.',
        );

        self::assertSame(
            $tagsBefore,
            (int) $this->db->fetchValue('SELECT COUNT(*) FROM tags'),
            'And must invent no tags.',
        );
    }
}
