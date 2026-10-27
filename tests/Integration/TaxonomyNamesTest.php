<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\NameKey;
use App\Domain\Role;
use App\Repository\CategoryRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\CategoryService;
use App\Service\LogoStorage;
use App\Service\PaymentMethodService;
use App\Service\SubscriptionService;
use App\Service\TagService;
use App\Service\ValidationException;
use App\Tests\Support\FakeUpload;
use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 51: a category, tag or payment-method name is matched whole, with case
 * and accents ignored — what MySQL's unique index already does, made true on
 * PostgreSQL too — and never as a substring of another name.
 */
final class TaxonomyNamesTest extends DatabaseTestCase
{
    private CategoryService $categories;
    private CategoryRepository $categoryRepository;
    private TagService $tagService;
    private TagRepository $tags;
    private PaymentMethodService $methods;
    private SubscriptionService $subscriptions;
    private Scope $scope;
    private string $logoDirectory;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->logoDirectory = sys_get_temp_dir() . '/renovo-taxonomy-logos-' . bin2hex(random_bytes(6));
        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/config/container.php')($builder, $settings);
        $builder->addDefinitions([LogoStorage::class => new LogoStorage($this->logoDirectory, 512 * 1024)]);
        $container = $builder->build();

        $this->categories = $container->get(CategoryService::class);
        $this->categoryRepository = $container->get(CategoryRepository::class);
        $this->tagService = $container->get(TagService::class);
        $this->tags = $container->get(TagRepository::class);
        $this->methods = $container->get(PaymentMethodService::class);
        $this->subscriptions = $container->get(SubscriptionService::class);

        $users = new UserRepository($this->db);
        $ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new \DateTimeImmutable());
        $household = (new HouseholdRepository($this->db))->create('Home', $ownerId);
        (new MembershipRepository($this->db))->create($household, $ownerId, Role::OwnerAdmin);
        $this->scope = Scope::forMember($ownerId, false, $household, Role::OwnerAdmin, IsolationMode::Shared);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logoDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->logoDirectory)) {
            rmdir($this->logoDirectory);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sameNames(): array
    {
        return [
            'case' => ['TV', 'tv'],
            'accent' => ['Café', 'Cafe'],
            'accent and case' => ['Café', 'CAFE'],
            'decomposed accent' => ["Cafe\u{0301}", 'Café'],
            'surrounding space' => ['Music', ' music '],
            'sharp s' => ['Straße', 'Strasse'],
            'capital and sharp s' => ['STRASSE', 'straße'],
        ];
    }

    #[DataProvider('sameNames')]
    public function testNameKeyTreatsCaseAndAccentVariantsAsOneName(string $a, string $b): void
    {
        self::assertTrue(NameKey::same($a, $b));
    }

    public function testNameKeyKeepsDifferentNamesApart(): void
    {
        self::assertFalse(NameKey::same('TV', 'Apple TV'));
        self::assertFalse(NameKey::same('Music', 'Music Videos'));
    }

    public function testASubstringNameIsNotAMatch(): void
    {
        $apple = $this->tags->create($this->scope, 'Apple TV');

        $ids = $this->tags->resolveOrCreate($this->scope, ['TV']);

        self::assertCount(1, $ids);
        self::assertNotSame($apple, $ids[0]);
        self::assertCount(2, $this->tags->findAll($this->scope));
        self::assertNull($this->tags->findByName($this->scope, 'Apple'));
    }

    public function testACaseVariantResolvesToTheExistingTag(): void
    {
        $tv = $this->tags->create($this->scope, 'TV');
        $this->tags->create($this->scope, 'Apple TV');

        self::assertSame([$tv], $this->tags->resolveOrCreate($this->scope, ['tv']));
        self::assertSame([$tv], $this->tags->resolveOrCreate($this->scope, ['TV', 'tV', 'Tv']));
        self::assertCount(2, $this->tags->findAll($this->scope));
    }

    public function testAnAccentVariantResolvesToTheExistingTag(): void
    {
        $cafe = $this->tags->create($this->scope, 'Café');

        self::assertSame([$cafe], $this->tags->resolveOrCreate($this->scope, ['Cafe']));
    }

    public function testATagNameContainedInAnotherIsNotADuplicate(): void
    {
        $this->tagService->create($this->scope, 'Apple TV');

        self::assertGreaterThan(0, $this->tagService->create($this->scope, 'TV'));
        $this->assertDuplicate(
            'error.tag.duplicate',
            fn () => $this->tagService->create($this->scope, 'tv'),
        );
    }

    public function testAnAccentVariantIsADuplicateTag(): void
    {
        $this->tagService->create($this->scope, 'Café');

        $this->assertDuplicate(
            'error.tag.duplicate',
            fn () => $this->tagService->create($this->scope, 'Cafe'),
        );
    }

    public function testANameContainedInAnotherIsNotADuplicateCategory(): void
    {
        $this->categories->create($this->scope, 'Music Videos', null);

        self::assertGreaterThan(0, $this->categories->create($this->scope, 'Music', null));
        self::assertSame('Music', $this->categoryRepository->findByName($this->scope, 'music')?->name);
        self::assertNull($this->categoryRepository->findByName($this->scope, 'Mus'));
    }

    public function testACaseVariantIsADuplicateCategory(): void
    {
        $this->categories->create($this->scope, 'Music Videos', null);
        $this->categories->create($this->scope, 'Music', null);

        $this->assertDuplicate(
            'error.category.duplicate',
            fn () => $this->categories->create($this->scope, 'music', null),
        );
    }

    public function testAnAccentVariantIsADuplicateCategory(): void
    {
        $this->categories->create($this->scope, 'Café', null);

        $this->assertDuplicate(
            'error.category.duplicate',
            fn () => $this->categories->create($this->scope, 'Cafe', null),
        );
    }

    public function testRenamingACategoryToAnAccentVariantOfAnotherIsADuplicate(): void
    {
        $this->categories->create($this->scope, 'Café', null);
        $id = $this->categories->create($this->scope, 'Bar', null);

        $this->assertDuplicate(
            'error.category.duplicate',
            fn () => $this->categories->rename($this->scope, $id, 'CAFE', null),
        );
    }

    public function testAnAccentVariantIsADuplicatePaymentMethod(): void
    {
        $this->methods->create($this->scope, 'Café card', null);

        $this->assertDuplicate(
            'error.payment_method.duplicate',
            fn () => $this->methods->create($this->scope, 'Cafe Card', null),
        );
    }

    public function testARenameKeepingTheSameNameIsNotADuplicate(): void
    {
        $id = $this->categories->create($this->scope, 'Café', null);

        $this->categories->rename($this->scope, $id, 'CAFÉ', '#112233');

        self::assertSame('#112233', $this->categoryRepository->find($this->scope, $id)?->colour);
    }

    public function testASubscriptionTaggedWithAContainedNameSavesAndSharesTheTag(): void
    {
        $this->tags->create($this->scope, 'Apple TV');

        $first = $this->subscriptions->create($this->scope, $this->input(['tags' => 'TV']));
        $second = $this->subscriptions->create($this->scope, $this->input(['tags' => 'tv, Apple tv']));

        self::assertCount(2, $this->tags->findAll($this->scope));
        self::assertSame(['TV'], $this->tagNamesOf($first));
        self::assertEqualsCanonicalizing(['TV', 'Apple TV'], $this->tagNamesOf($second));
    }

    public function testTwentyFiveTagsAndAFiftyCharacterTagAreAccepted(): void
    {
        $names = array_map(static fn (int $n): string => 'tag' . $n, range(1, 24));
        $names[] = str_repeat('x', 50);

        $id = $this->subscriptions->create($this->scope, $this->input(['tags' => implode(',', $names)]));

        self::assertCount(25, $this->tagNamesOf($id));
        self::assertContains(str_repeat('x', 50), $this->tagNamesOf($id));
    }

    public function testMoreThanTwentyFiveTagsAreRefused(): void
    {
        $names = array_map(static fn (int $n): string => 'tag' . $n, range(1, 26));

        try {
            $this->subscriptions->create($this->scope, $this->input(['tags' => $names]));
            self::fail('26 tags should be refused');
        } catch (ValidationException $exception) {
            self::assertSame(['tags' => 'error.tags.too_many_25'], $this->keys($exception));
        }

        self::assertSame([], $this->tags->findAll($this->scope), 'a refused save creates no tags');
    }

    public function testATagOverFiftyCharactersIsRefused(): void
    {
        try {
            $this->subscriptions->create($this->scope, $this->input(['tags' => 'ok, ' . str_repeat('x', 51)]));
            self::fail('a 51-character tag should be refused');
        } catch (ValidationException $exception) {
            self::assertSame(['tags' => 'error.tag.too_long_50'], $this->keys($exception));
        }

        self::assertSame([], $this->tags->findAll($this->scope));
    }

    /**
     * Two saves can both pass the duplicate check; the unique index then
     * refuses the second. A second unique index (on the colour) stands in for
     * the name index here, because the check itself already stops every name
     * collision a single request can make: what matters is that a violation
     * reaches the member as the duplicate message and not as a server error.
     */
    public function testACategoryRaceLostToTheUniqueIndexIsTheDuplicateMessage(): void
    {
        $this->withUniqueColour('categories', function (): void {
            $this->categories->create($this->scope, 'First', '#112233');
            $other = $this->categories->create($this->scope, 'Other', '#445566');

            $this->assertDuplicate(
                'error.category.duplicate',
                fn () => $this->categories->create($this->scope, 'Second', '#112233'),
            );
            $this->assertDuplicate(
                'error.category.duplicate',
                fn () => $this->categories->rename($this->scope, $other, 'Other', '#112233'),
            );
        });
    }

    public function testAPaymentMethodRaceLostToTheUniqueIndexIsTheDuplicateMessage(): void
    {
        $this->withUniqueColour('payment_methods', function (): void {
            $this->methods->create($this->scope, 'First', '#112233');
            $other = $this->methods->create($this->scope, 'Other', '#445566');

            $this->assertDuplicate(
                'error.payment_method.duplicate',
                fn () => $this->methods->create($this->scope, 'Second', '#112233'),
            );
            $this->assertDuplicate(
                'error.payment_method.duplicate',
                fn () => $this->methods->update($this->scope, $other, 'Other', '#112233'),
            );
        });
    }

    /**
     * K133: the logo is stored before the row, so a create or update that loses
     * the race has to take it back out.
     */
    public function testALostPaymentMethodRaceDeletesItsLogo(): void
    {
        $this->withUniqueColour('payment_methods', function (): void {
            $this->methods->create($this->scope, 'First', '#112233');
            $other = $this->methods->create($this->scope, 'Other', '#445566');

            $this->assertDuplicate(
                'error.payment_method.duplicate',
                fn () => $this->methods->create($this->scope, 'Second', '#112233', FakeUpload::png()),
            );
            self::assertSame([], $this->storedLogos(), 'A create that lost the race leaves no file.');

            $this->assertDuplicate(
                'error.payment_method.duplicate',
                fn () => $this->methods->update($this->scope, $other, 'Other', '#112233', FakeUpload::png()),
            );
            self::assertSame([], $this->storedLogos(), 'An update that lost the race leaves no file.');
        });
    }

    /**
     * @return list<string>
     */
    private function storedLogos(): array
    {
        return glob($this->logoDirectory . '/*') ?: [];
    }

    public function testSharpSAndSSAreOneTagOnBothEngines(): void
    {
        $first = $this->tags->resolveOrCreate($this->scope, ['Strasse']);
        $second = $this->tags->resolveOrCreate($this->scope, ['Straße']);

        self::assertSame($first, $second);
        self::assertCount(1, $this->tags->findAll($this->scope));

        $this->assertDuplicate('error.tag.duplicate', fn () => $this->tagService->create($this->scope, 'STRASSE'));
    }

    public function testALostRaceResolvesToTheExistingTag(): void
    {
        // Another request inserts the tag after this one has looked and found
        // nothing: the lookup is made, then the create hits the unique index.
        $racing = new class ($this->db, $this->scope) extends TagRepository {
            private bool $raced = false;

            public function __construct(\App\Persistence\Database $db, private readonly Scope $racer)
            {
                parent::__construct($db);
            }

            public function findAll(Scope $scope): array
            {
                $found = parent::findAll($scope);
                if (!$this->raced) {
                    $this->raced = true;
                    $this->create($this->racer, 'shared');
                }

                return $found;
            }
        };

        $ids = $racing->resolveOrCreate($this->scope, ['shared']);

        self::assertCount(1, $ids);
        self::assertCount(1, $this->tags->findAll($this->scope));
        self::assertSame($ids[0], $this->tags->findByName($this->scope, 'shared')?->id);
    }

    /**
     * The other request is a second connection that commits between this
     * transaction's first read and its insert. On MySQL that first read fixed
     * the snapshot, so only a locking lookup finds the tag; PostgreSQL would
     * also abort the transaction at the failed insert without a savepoint.
     */
    public function testALostRaceInsideATransactionLeavesItUsable(): void
    {
        $config = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];
        $other = new TagRepository(new \App\Persistence\Database(
            $config,
            \App\Persistence\PlatformFactory::create($config['driver']),
        ));

        $racing = new class ($this->db, $other, $this->scope) extends TagRepository {
            private bool $raced = false;

            public function __construct(
                \App\Persistence\Database $db,
                private readonly TagRepository $other,
                private readonly Scope $racer,
            ) {
                parent::__construct($db);
            }

            public function findAll(Scope $scope): array
            {
                $found = parent::findAll($scope);
                if (!$this->raced) {
                    $this->raced = true;
                    $this->other->create($this->racer, 'shared');
                }

                return $found;
            }
        };

        $ids = $this->db->transactional(function () use ($racing): array {
            $ids = $racing->resolveOrCreate($this->scope, ['shared', 'later']);
            // PostgreSQL would refuse every statement after a failed insert.
            $this->tags->findAll($this->scope);

            return $ids;
        });

        self::assertCount(2, $ids);
        self::assertCount(2, $this->tags->findAll($this->scope));
    }

    public function testACreateThatLosesTheRaceShowsTheDuplicateMessage(): void
    {
        $this->tags->create($this->scope, 'Taken');
        $service = new TagService($this->blindTagRepository());

        $this->assertDuplicate('error.tag.duplicate', fn () => $service->create($this->scope, 'Taken'));
        self::assertCount(1, $this->tags->findAll($this->scope));
    }

    public function testARenameThatLosesTheRaceShowsTheDuplicateMessage(): void
    {
        $this->tags->create($this->scope, 'Taken');
        $other = $this->tags->create($this->scope, 'Other');
        $service = new TagService($this->blindTagRepository());

        $this->assertDuplicate('error.tag.duplicate', fn () => $service->rename($this->scope, $other, 'Taken'));
    }

    /**
     * A repository whose duplicate check finds nothing, as it would for a
     * request that validated just before another one created the tag: the
     * insert then meets the unique index.
     */
    private function blindTagRepository(): TagRepository
    {
        return new class ($this->db) extends TagRepository {
            public function findByName(Scope $scope, string $name): ?\App\Domain\Entity\Tag
            {
                return null;
            }
        };
    }

    public function testOnlyAUniqueViolationBecomesTheDuplicateMessage(): void
    {
        $method = new \ReflectionMethod($this->tagService, 'duplicateOrRethrow');

        $unique = new \PDOException('duplicate key');
        $unique->errorInfo = ['23505', 7, 'duplicate key'];
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($unique, '23505');
        $other = new \PDOException('connection lost');
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($other, '08006');

        $mapped = $method->invoke($this->tagService, $unique);
        self::assertInstanceOf(ValidationException::class, $mapped);
        self::assertSame(['name' => 'error.tag.duplicate'], $this->keys($mapped));
        self::assertSame($other, $method->invoke($this->tagService, $other));
    }

    private function withUniqueColour(string $table, callable $work): void
    {
        $this->db->execute(sprintf('CREATE UNIQUE INDEX tmp_unique_colour ON %s (household_id, colour)', $table));

        try {
            $work();
        } finally {
            $this->db->execute(
                $this->env('DB_DRIVER', 'pgsql') === 'mysql'
                    ? sprintf('DROP INDEX tmp_unique_colour ON %s', $table)
                    : 'DROP INDEX tmp_unique_colour',
            );
        }
    }

    private function assertDuplicate(string $key, callable $save): void
    {
        try {
            $save();
            self::fail('the duplicate name should have been refused');
        } catch (ValidationException $exception) {
            self::assertSame(['name' => $key], $this->keys($exception));
        }
    }

    /**
     * @return array<string, string>
     */
    private function keys(ValidationException $exception): array
    {
        return array_map(static fn ($error): string => $error->key, $exception->errors());
    }

    /**
     * @return list<string>
     */
    private function tagNamesOf(int $id): array
    {
        $subscription = $this->subscriptions->find($this->scope, $id);

        return $subscription === null
            ? []
            : array_map(static fn ($tag): string => $tag->name, $subscription->tags);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Streaming',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ];
    }
}
