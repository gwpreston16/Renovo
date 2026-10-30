<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\MembershipStatus;
use App\Domain\RatesState;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Domain\SubscriptionFilter;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Service\ShellService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The shell's three reads, and the rates chip.
 *
 * The frame holds no figures of its own, so what is tested here is that the
 * little it does say is scoped: the Subscriptions badge is the count the
 * reader could list and no more, the household label names the reader's own
 * role, and the bell's dot is lit by what needs acting on rather than by
 * every renewal. `ShellTest` checks that the page draws them.
 */
final class ShellServiceTest extends DatabaseTestCase
{
    private const TODAY = '2026-06-15 09:00:00';

    private ShellService $shell;
    private SubscriptionRepository $subscriptions;
    private InstanceSettingsService $settings;
    private ExchangeRateRepository $rates;

    private int $householdId;
    private int $ownerId;
    private int $editorId;
    private int $contributorId;
    private int $viewerId;

    protected function setUp(): void
    {
        parent::setUp();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(true, [
            SessionInterface::class => new ArraySession(),
            MailerInterface::class => new RecordingMailer(),
            Clock::class => FrozenClock::at(self::TODAY),
        ]);

        $container = $app->getContainer();
        self::assertNotNull($container);

        $this->shell = $container->get(ShellService::class);
        $this->settings = $container->get(InstanceSettingsService::class);
        $this->settings->setBaseCurrency('GBP');

        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->rates = new ExchangeRateRepository($this->db);

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $now = new DateTimeImmutable();

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $this->contributorId = $users->create('contributor@example.test', 'Contributor', 'hash', false, $now);
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, $now);

        $this->householdId = (new HouseholdRepository($this->db))->create('The Jenkins household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->contributorId, Role::Contributor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);
    }

    /**
     * In ISOLATED mode the badge is the reader's own active rows plus the
     * splits they share — never the household's total, and never a paused row.
     */
    public function testTheBadgeCountsWhatTheReaderCanListInIsolatedMode(): void
    {
        $owner = $this->scope($this->ownerId, Role::OwnerAdmin, IsolationMode::Isolated);
        $editor = $this->scope($this->editorId, Role::Editor, IsolationMode::Isolated);

        $this->subscription($owner, 'Owner one');
        $this->subscription($owner, 'Owner two');
        $this->subscription($owner, 'Owner paused', active: false);
        $shared = $this->subscription($owner, 'Shared with the editor');
        $this->subscription($editor, 'Editor one');

        // As SplitService does: the mode first. Only a live split widens (K156).
        (new SubscriptionRepository($this->db))->setSplitMode($owner, $shared, SplitMode::Equal);
        (new SplitRepository($this->db))->replaceForSubscription($owner, $shared, $this->ownerId, [
            ['user_id' => $this->ownerId, 'share_units' => 1],
            ['user_id' => $this->editorId, 'share_units' => 1],
        ]);

        self::assertSame(3, $this->badge($owner), "The owner's own three, not the editor's.");
        self::assertSame(2, $this->badge($editor), 'Their own row and the split they share.');

        $viewer = $this->scope($this->viewerId, Role::Viewer, IsolationMode::Isolated);
        self::assertSame(0, $this->badge($viewer), 'A Viewer who owns nothing lists nothing.');
    }

    /**
     * In SHARED mode everybody reads the household, so the badge is its active
     * total; a Viewer's badge is exactly what their list would show them.
     */
    public function testTheBadgeIsTheCountTheListWouldShowInSharedMode(): void
    {
        $owner = $this->scope($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);
        $this->subscription($owner, 'One');
        $this->subscription($owner, 'Two');
        $this->subscription($owner, 'Paused', active: false);
        $this->subscription($this->scope($this->editorId, Role::Editor, IsolationMode::Shared), 'Three');

        $viewer = $this->scope($this->viewerId, Role::Viewer, IsolationMode::Shared);
        $listed = $this->subscriptions->findForList($viewer, new SubscriptionFilter(perPage: 100));

        self::assertSame(3, $this->shell->forScope($viewer)->subscriptionCount);
        self::assertCount(3, $listed, 'The badge and the list disagree.');
    }

    /**
     * The household label names the household, how many people are in it and
     * the reader's own role — each of the four.
     */
    public function testTheHouseholdLabelNamesTheReadersOwnRole(): void
    {
        $members = [
            [$this->ownerId, Role::OwnerAdmin],
            [$this->editorId, Role::Editor],
            [$this->contributorId, Role::Contributor],
            [$this->viewerId, Role::Viewer],
        ];

        foreach ($members as [$userId, $role]) {
            $label = $this->shell->forScope($this->scope($userId, $role, IsolationMode::Shared))->household;

            self::assertNotNull($label);
            self::assertSame('The Jenkins household', $label->name);
            self::assertSame(4, $label->memberCount);
            self::assertSame($role, $label->role);
        }
    }

    /**
     * Somebody invited and not yet arrived is not yet a member.
     */
    public function testAPendingInviteIsNotCountedAsAMember(): void
    {
        $users = new UserRepository($this->db);
        $invited = $users->create('invited@example.test', 'Invited', 'hash', false, new DateTimeImmutable());
        (new MembershipRepository($this->db))
            ->create($this->householdId, $invited, Role::Viewer, MembershipStatus::Pending);

        $label = $this->shell->forScope($this->scope($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared))
            ->household;

        self::assertSame(4, $label?->memberCount);
    }

    public function testAnAccountWithNoHouseholdHasNoLabelBadgeOrDot(): void
    {
        $shell = $this->shell->forScope(Scope::withoutHousehold($this->ownerId, true, IsolationMode::Shared));

        self::assertNull($shell->household);
        self::assertNull($shell->subscriptionCount);
        self::assertFalse($shell->somethingDueSoon);
    }

    /**
     * Fresh, stale and unavailable, from what the cache records. None of them
     * is arrived at by fetching; `ShellTest` counts the requests.
     */
    public function testTheRatesChipHasThreeStates(): void
    {
        $scope = $this->scope($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        $chip = $this->shell->forScope($scope)->rates;
        self::assertSame(RatesState::Unavailable, $chip->state);
        self::assertNull($chip->refreshedAt);
        self::assertSame('GBP', $chip->currency);

        $fetched = new DateTimeImmutable('2026-06-15 07:00:00');
        $this->rates->replaceBase('GBP', [ExchangeRate::of('GBP', 'EUR', 1_170_000)], $fetched);

        $chip = $this->shell->forScope($scope)->rates;
        self::assertSame(RatesState::Fresh, $chip->state);
        self::assertEquals($fetched, $chip->refreshedAt);

        $longAgo = new DateTimeImmutable('2026-06-01 07:00:00');
        $this->rates->replaceBase('GBP', [ExchangeRate::of('GBP', 'EUR', 1_170_000)], $longAgo);

        self::assertSame(RatesState::Stale, $this->shell->forScope($scope)->rates->state);
    }

    /**
     * The chip leads to the rate settings only for whoever may change them.
     */
    public function testTheRatesChipLinksOnlyForAnInstanceAdministrator(): void
    {
        $admin = Scope::forMember($this->ownerId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);

        self::assertTrue($this->shell->forScope($admin)->rates->linksToSettings);
        $owner = $this->scope($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);
        self::assertFalse($this->shell->forScope($owner)->rates->linksToSettings);
    }

    /**
     * The dot means something needs acting on: a trial converting or a
     * cancel-by deadline inside the urgent window. A plain renewal next week
     * does not light it — nearly every household has one.
     */
    public function testTheBellDotIsLitByATrialOrADeadlineAndNotByARenewal(): void
    {
        $owner = $this->scope($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        $this->subscription($owner, 'Renews next week', next: '2026-06-20');
        self::assertFalse($this->shell->forScope($owner)->somethingDueSoon, 'A renewal lit the dot.');

        $this->subscription($owner, 'Trial ends far away', trialEnds: '2026-08-01');
        self::assertFalse($this->shell->forScope($owner)->somethingDueSoon, 'A distant trial lit the dot.');

        $this->subscription($owner, 'Trial ends soon', trialEnds: '2026-06-25');
        self::assertTrue($this->shell->forScope($owner)->somethingDueSoon);
    }

    public function testTheBellDotIsLitByACancelByDeadlineInsideTheWindow(): void
    {
        $owner = $this->scope($this->ownerId, Role::OwnerAdmin, IsolationMode::Shared);

        // Renews on 1 August with 30 days' notice: the deadline is 2 July,
        // seventeen days away — outside the fourteen.
        $this->subscription($owner, 'Notice, but not yet', next: '2026-08-01', noticeDays: 30);
        self::assertFalse($this->shell->forScope($owner)->somethingDueSoon);

        // Renews on 20 July with 30 days' notice: the deadline is 20 June.
        $this->subscription($owner, 'Notice due', next: '2026-07-20', noticeDays: 30);
        self::assertTrue($this->shell->forScope($owner)->somethingDueSoon);
    }

    /**
     * What lights the dot is scoped like everything else: in ISOLATED mode
     * another member's trial is not the reader's to be told about.
     */
    public function testTheBellDotIgnoresRowsTheReaderCannotSee(): void
    {
        $editor = $this->scope($this->editorId, Role::Editor, IsolationMode::Isolated);
        $this->subscription($editor, "The editor's trial", trialEnds: '2026-06-20');

        $viewer = $this->scope($this->viewerId, Role::Viewer, IsolationMode::Isolated);
        self::assertFalse($this->shell->forScope($viewer)->somethingDueSoon);
        self::assertTrue($this->shell->forScope($editor)->somethingDueSoon);
    }

    /**
     * K168: the dot reads only the rows that can have a deadline. Rows with
     * neither a notice period nor a contract end never light it, a contract
     * end inside the window does, and in ISOLATED mode only for its owner.
     */
    public function testTheDueSoonBadgeIsUnchangedAndScoped(): void
    {
        $editor = $this->scope($this->editorId, Role::Editor, IsolationMode::Isolated);
        $viewer = $this->scope($this->viewerId, Role::Viewer, IsolationMode::Isolated);
        $sharedViewer = $this->scope($this->viewerId, Role::Viewer, IsolationMode::Shared);

        foreach (range(1, 5) as $n) {
            $this->subscription($editor, 'Plain ' . $n, next: '2026-06-16');
        }
        self::assertFalse($this->shell->forScope($editor)->somethingDueSoon, 'A row with no deadline lit the dot.');

        $id = $this->subscription($editor, 'Contract ending', next: '2026-12-01');
        $this->db->execute(
            "UPDATE subscriptions SET contract_ends_on = '2026-06-22' WHERE id = :id",
            ['id' => $id],
        );

        self::assertTrue($this->shell->forScope($editor)->somethingDueSoon);
        self::assertTrue($this->shell->forScope($sharedViewer)->somethingDueSoon);
        self::assertFalse($this->shell->forScope($viewer)->somethingDueSoon, "Another member's deadline lit the dot.");
    }

    private function badge(Scope $scope): ?int
    {
        return $this->shell->forScope($scope)->subscriptionCount;
    }

    private function scope(int $userId, Role $role, IsolationMode $mode): Scope
    {
        return Scope::forMember($userId, false, $this->householdId, $role, $mode);
    }

    private function subscription(
        Scope $scope,
        string $name,
        bool $active = true,
        string $next = '2026-12-01',
        ?string $trialEnds = null,
        ?int $noticeDays = null,
    ): int {
        return $this->subscriptions->create($scope, [
            'name' => $name,
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next,
            'is_active' => $active,
            'is_trial' => $trialEnds !== null,
            'trial_end_date' => $trialEnds,
            'notice_period_amount' => $noticeDays,
            'notice_period_unit' => $noticeDays === null ? null : 'days',
        ], []);
    }
}
