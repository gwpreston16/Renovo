<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Domain\SubscriptionFilter;
use App\Domain\Visibility;
use App\Domain\WeekStart;
use App\Repository\AttachmentRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Security\SessionInterface;
use App\Service\BulkActionService;
use App\Service\CalendarFeedService;
use App\Service\CalendarService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\StatsService;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * "Only me": a subscription its payer keeps to themselves.
 *
 * Invisible to every other member of the household, in SHARED and ISOLATED,
 * whatever their role — Owner/Admins included — and absent from their totals.
 * Enforced by the scoping layer, so every consumer below is simply asked and
 * none of them was taught about it. SHARED is where it matters most: it is the
 * default, and it is the mode in which everything else in the household is
 * visible to everybody.
 *
 * Pat (an Editor) owns the private row, Therapy at £50 a month, and a public
 * one, Broadband at £30. Alice is the Owner/Admin.
 */
final class PrivateSubscriptionTest extends DatabaseTestCase
{
    private ContainerInterface $container;
    private SubscriptionService $subscriptions;

    private int $alice;
    private int $pat;
    private int $ed;
    private int $cora;
    private int $vic;
    private int $household;

    private int $therapy;
    private int $broadband;

    protected function setUp(): void
    {
        parent::setUp();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(true, [
            SessionInterface::class => new ArraySession(),
            MailerInterface::class => new RecordingMailer(),
        ]);
        $container = $app->getContainer();
        self::assertNotNull($container);
        $this->container = $container;

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setBaseCurrency('GBP');
        $settings->markRatesAttempted(new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $now = new DateTimeImmutable();

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash', false, $now);
        $this->pat = $users->create('pat@example.test', 'Pat', 'hash', false, $now);
        $this->ed = $users->create('ed@example.test', 'Ed', 'hash', false, $now);
        $this->cora = $users->create('cora@example.test', 'Cora', 'hash', false, $now);
        $this->vic = $users->create('vic@example.test', 'Vic', 'hash', false, $now);

        $this->household = (new HouseholdRepository($this->db))->create('House', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->pat, Role::Editor);
        $memberships->create($this->household, $this->ed, Role::Editor);
        $memberships->create($this->household, $this->cora, Role::Contributor);
        $memberships->create($this->household, $this->vic, Role::Viewer);

        $this->subscriptions = $container->get(SubscriptionService::class);

        $this->therapy = $this->subscriptions->create($this->scope($this->pat), $this->input('Therapy', '50.00', [
            'visibility' => Visibility::Payer->value,
        ]));
        $this->broadband = $this->subscriptions->create($this->scope($this->pat), $this->input('Broadband', '30.00'));
    }

    /**
     * @return iterable<string, array{string, IsolationMode}>
     */
    public static function otherMembers(): iterable
    {
        foreach ([IsolationMode::Shared, IsolationMode::Isolated] as $mode) {
            $roles = ['alice' => 'Owner/Admin', 'ed' => 'Editor', 'cora' => 'Contributor', 'vic' => 'Viewer'];
            foreach ($roles as $who => $role) {
                yield $role . ', ' . $mode->value => [$who, $mode];
            }
        }
    }

    /**
     * @dataProvider otherMembers
     */
    public function testAnotherMemberCannotFindListOrSearchIt(string $who, IsolationMode $mode): void
    {
        $scope = $this->scope($this->{$who}, $mode);

        self::assertNull($this->subscriptions->find($scope, $this->therapy));

        $all = (new SubscriptionFilter())->withIncludeInactive();
        self::assertNotContains($this->therapy, $this->ids($this->subscriptions->list($scope, $all)));

        $search = SubscriptionFilter::fromQueryParams(['q' => 'Therapy']);
        self::assertSame(0, $this->subscriptions->count($scope, $search));
        self::assertNotContains(
            $this->therapy,
            $this->ids($this->subscriptions->allForStats($scope, activeOnly: false)),
        );
    }

    /**
     * @dataProvider otherMembers
     */
    public function testItIsAbsentFromTheirForecastCalendarAndFeed(string $who, IsolationMode $mode): void
    {
        $scope = $this->scope($this->{$who}, $mode);

        $charged = array_map(
            static fn (array $charge): int => $charge['subscription']->id,
            $this->container->get(ForecastService::class)->charges($scope, 12),
        );
        self::assertNotContains($this->therapy, $charged);

        $month = $this->container->get(CalendarService::class)->month(
            $scope,
            new DateTimeImmutable('first day of next month'),
            WeekStart::Monday,
        );
        self::assertStringNotContainsString('Therapy', (string) json_encode($month));

        $feed = $this->container->get(CalendarFeedService::class)->build($scope, 'Renovo');
        self::assertStringNotContainsString('Therapy', $feed);
    }

    /**
     * @dataProvider otherMembers
     */
    public function testItsHistoryAndDocumentsAreHiddenWithIt(string $who, IsolationMode $mode): void
    {
        $this->container->get(AttachmentRepository::class)->create(
            $this->scope($this->pat),
            $this->therapy,
            $this->pat,
            null,
            'invoice.pdf',
            'attachments/private.pdf',
            'application/pdf',
            100,
            $this->pat,
        );

        $scope = $this->scope($this->{$who}, $mode);

        self::assertArrayNotHasKey(
            $this->therapy,
            $this->container->get(PriceHistoryService::class)->historyBySubscription($scope),
        );
        self::assertSame([], $this->container->get(AttachmentRepository::class)
            ->findForSubscription($scope, $this->therapy));
    }

    /**
     * @dataProvider modes
     */
    public function testThePayerSeesItInEitherMode(IsolationMode $mode): void
    {
        $scope = $this->scope($this->pat, $mode);

        self::assertSame(Visibility::Payer, $this->subscriptions->find($scope, $this->therapy)?->visibility);
        self::assertArrayHasKey(
            $this->therapy,
            $this->container->get(PriceHistoryService::class)->historyBySubscription($scope),
        );
    }

    public function testAHouseholdTotalSeenByAnotherMemberIsTheTotalWithoutIt(): void
    {
        $stats = $this->container->get(StatsService::class);

        self::assertSame(3000, $stats->dashboard($this->scope($this->alice))['combined_monthly']['amount_minor']);
        self::assertSame(8000, $stats->dashboard($this->scope($this->pat))['combined_monthly']['amount_minor']);
    }

    public function testAnOwnerAdminCannotChangeItByGuessingItsId(): void
    {
        $alice = $this->scope($this->alice);

        foreach (
            [
            fn () => $this->subscriptions->setActive($alice, $this->therapy, false),
            fn () => $this->subscriptions->cancel($alice, $this->therapy),
            fn () => $this->subscriptions->delete($alice, $this->therapy),
            ] as $write
        ) {
            try {
                $write();
                self::fail('A write reached a private row.');
            } catch (ScopeViolationException) {
                // The write predicate carries the privacy clause too.
            }
        }

        $changed = $this->container->get(BulkActionService::class)->apply($alice, [
            'action' => BulkActionService::ACTION_DEACTIVATE,
            'ids' => [$this->therapy, $this->broadband],
        ]);
        self::assertSame(1, $changed);
        self::assertTrue($this->subscriptions->find($this->scope($this->pat), $this->therapy)?->isActive);
    }

    public function testSplitWideningCannotReopenIt(): void
    {
        // Not reachable through the services, which refuse a split on a private
        // row; written directly to prove the predicate's order rather than the
        // validation. In ISOLATED mode a split participation widens reads — and
        // the privacy clause sits outside that widening.
        // A live split, or the widening would not apply at all (K156).
        $this->db->execute(
            "UPDATE subscriptions SET split_mode = 'equal' WHERE id = :id",
            ['id' => $this->therapy],
        );
        $this->db->insert('subscription_splits', [
            'subscription_id' => $this->therapy,
            'household_id' => $this->household,
            'owner_user_id' => $this->pat,
            'user_id' => $this->ed,
            'share_units' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        self::assertNull($this->subscriptions->find($this->scope($this->ed, IsolationMode::Isolated), $this->therapy));
        self::assertNull($this->subscriptions->find($this->scope($this->ed), $this->therapy));
    }

    public function testAPrivateRowCannotBeSplit(): void
    {
        $this->assertRefused('split_mode', 'error.split.private', fn () => $this->container
            ->get(SplitService::class)
            ->update($this->scope($this->pat), $this->therapy, [
                'split_mode' => SplitMode::Equal->value,
                'shares' => [$this->pat => 1, $this->ed => 1],
            ]));
    }

    public function testASplitRowCannotBePrivate(): void
    {
        $this->container->get(SplitService::class)->update($this->scope($this->pat), $this->broadband, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->pat => 1, $this->ed => 1],
        ]);

        $this->assertRefused('visibility', 'error.visibility.split', fn () => $this->subscriptions->update(
            $this->scope($this->pat),
            $this->broadband,
            $this->input('Broadband', '30.00', ['visibility' => Visibility::Payer->value]),
        ));
    }

    public function testOnlyTheOwnerMayMakeARowPrivate(): void
    {
        // Alice, for Pat: a row that would vanish from her screen on save.
        $this->assertRefused('visibility', 'error.visibility.owner_only', fn () => $this->subscriptions->create(
            $this->scope($this->alice),
            $this->input('For Pat', '5.00', [
                'owner_user_id' => (string) $this->pat,
                'visibility' => Visibility::Payer->value,
            ]),
        ));
    }

    public function testAPrivateRowIsPaidByItsOwner(): void
    {
        $this->assertRefused('visibility', 'error.visibility.payer_is_owner', fn () => $this->subscriptions->create(
            $this->scope($this->pat),
            $this->input('Mine', '5.00', [
                'payer_user_id' => (string) $this->ed,
                'visibility' => Visibility::Payer->value,
            ]),
        ));
    }

    public function testAnEditThatDoesNotMentionVisibilityKeepsIt(): void
    {
        $input = $this->input('Therapy', '55.00');
        unset($input['visibility']);

        $this->subscriptions->update($this->scope($this->pat), $this->therapy, $input);

        $therapy = $this->subscriptions->find($this->scope($this->pat), $this->therapy);
        self::assertSame(Visibility::Payer, $therapy?->visibility);
    }

    public function testABulkReassignmentLeavesPrivateRowsWithTheirOwner(): void
    {
        $changed = $this->container->get(BulkActionService::class)->apply($this->scope($this->pat), [
            'action' => BulkActionService::ACTION_OWNER,
            'ids' => [$this->therapy, $this->broadband],
            'user_id' => (string) $this->ed,
        ]);

        self::assertSame(1, $changed);
        $therapy = $this->subscriptions->find($this->scope($this->pat), $this->therapy);
        self::assertSame($this->pat, $therapy?->ownerUserId);
    }

    public function testTheBackupScreenCanSayHowManyItLeavesOut(): void
    {
        self::assertSame(1, $this->subscriptions->countPrivateToOthers($this->scope($this->alice)));
        self::assertSame(0, $this->subscriptions->countPrivateToOthers($this->scope($this->pat)));
    }

    /**
     * @return iterable<string, array{IsolationMode}>
     */
    public static function modes(): iterable
    {
        yield 'shared' => [IsolationMode::Shared];
        yield 'isolated' => [IsolationMode::Isolated];
    }

    private function scope(int $userId, IsolationMode $mode = IsolationMode::Shared): Scope
    {
        $role = match ($userId) {
            $this->alice => Role::OwnerAdmin,
            $this->cora => Role::Contributor,
            $this->vic => Role::Viewer,
            default => Role::Editor,
        };

        return Scope::forMember($userId, false, $this->household, $role, $mode);
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function input(string $name, string $price, array $overrides = []): array
    {
        return $overrides + [
            'name' => $name,
            'price' => $price,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'visibility' => Visibility::Household->value,
        ];
    }

    /**
     * @param list<\App\Domain\Entity\Subscription> $subscriptions
     * @return list<int>
     */
    private function ids(array $subscriptions): array
    {
        return array_map(static fn ($subscription): int => $subscription->id, $subscriptions);
    }

    private function assertRefused(string $field, string $key, callable $attempt): void
    {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            self::assertArrayHasKey($field, $errors);
            self::assertSame($key, $errors[$field]->key);

            return;
        }

        self::fail('The write was accepted.');
    }
}
