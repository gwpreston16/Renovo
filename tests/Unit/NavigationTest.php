<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\IsolationMode;
use App\Domain\NavLink;
use App\Domain\Navigation;
use App\Domain\Role;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Service\NavigationService;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\TestTranslator;

/**
 * Which navigation item a page belongs to, and who can see which items.
 *
 * The shell's whole job is to be right about where you are, and the way that
 * goes wrong is prefixes: `/settings` is the start of `/settings/notifications`
 * and `/` is the start of everything. So the interesting assertion is not
 * "Settings is active on the settings page" but "exactly one item is, and it is
 * that one" — which is what this makes of every path the application serves.
 */
final class NavigationTest extends TestCase
{
    private NavigationService $navigation;

    protected function setUp(): void
    {
        $this->navigation = new NavigationService(new PermissionService(TestTranslator::create()));
    }

    /**
     * Every path the shell can be rendered on, and the item it belongs to.
     *
     * The user card is an item here like any rail row — `nav.profile` — so
     * "exactly one" counts it too. A null expectation means no item claims
     * the path.
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function paths(): array
    {
        return [
            'the dashboard' => ['/', 'nav.dashboard'],
            'the list' => ['/subscriptions', 'nav.subscriptions'],
            'a new subscription' => ['/subscriptions/new', 'nav.subscriptions'],
            'editing one' => ['/subscriptions/12/edit', 'nav.subscriptions'],
            'its money' => ['/subscriptions/12/money', 'nav.subscriptions'],
            // Cancel-by lost its row; the list it is linked from is lit.
            'cancel-by, which is the list' => ['/cancellations', 'nav.subscriptions'],
            'statistics' => ['/stats', 'nav.analytics'],
            'the forecast, which is analytics too' => ['/forecast', 'nav.analytics'],
            'the calendar' => ['/calendar', 'nav.calendar'],
            'budgets' => ['/budgets', 'nav.budgets'],
            'a new budget' => ['/budgets/new', 'nav.budgets'],
            'the member screen' => ['/settings/members', 'nav.members'],
            'removing a member' => ['/settings/members/4/remove', 'nav.members'],
            'inviting somebody' => ['/settings/members/invite', 'nav.members'],
            'settings' => ['/settings', 'nav.settings'],
            // The pages that lost their rows light Settings, whose page links
            // to each of them.
            'the data tab is settings' => ['/settings/data', 'nav.settings'],
            'the instance tab is settings' => ['/settings/instance', 'nav.settings'],
            'import is settings' => ['/import', 'nav.settings'],
            'the import mapping step' => ['/import/map', 'nav.settings'],
            'the audit log is settings' => ['/audit', 'nav.settings'],
            'api tokens are settings' => ['/settings/api-tokens', 'nav.settings'],
            'backup is settings' => ['/settings/backup', 'nav.settings'],
            // Starts with "/settings" and is not Settings: per-person, and its
            // own row.
            'alerts, which are not settings' => ['/settings/notifications', 'nav.notifications'],
            'your own page, which is the user card' => ['/profile', 'nav.profile'],
            'your account details' => ['/profile/account', 'nav.profile'],
            // Deliberately claimed by nothing: a path that merely begins with
            // the same letters as a section is not part of it.
            'a path that only looks like the list' => ['/subscriptions-archive', null],
        ];
    }

    /**
     * @dataProvider paths
     */
    public function testExactlyOneItemClaimsEachPath(string $path, ?string $expected): void
    {
        $navigation = $this->navigation->forPath($this->owner(), $path);

        self::assertSame(
            $expected === null ? [] : [$expected],
            $this->activeLabels($navigation),
            sprintf('%s should be %s.', $path, $expected ?? 'claimed by no navigation item'),
        );
    }

    /**
     * The rail is the prototype's: three screens you read, then the household
     * tools, in that order.
     */
    public function testTheRailIsTheMainGroupThenTheHouseholdTools(): void
    {
        $navigation = $this->navigation->forPath($this->owner(), '/');

        self::assertSame(
            ['nav.dashboard', 'nav.subscriptions', 'nav.analytics'],
            $this->labels($navigation->primary),
        );
        self::assertSame(
            ['nav.budgets', 'nav.calendar', 'nav.members', 'nav.notifications', 'nav.settings'],
            $this->labels($navigation->tools),
        );
        self::assertSame('/profile', $navigation->account->href);
    }

    /**
     * The root does not claim every page in the application.
     */
    public function testTheDashboardClaimsOnlyTheDashboard(): void
    {
        self::assertSame(['nav.dashboard'], $this->activeLabels($this->navigation->forPath($this->owner(), '/')));
        self::assertSame(
            ['nav.calendar'],
            $this->activeLabels($this->navigation->forPath($this->owner(), '/calendar')),
        );
    }

    /**
     * Members & roles is one screen for every member of the household: an
     * Owner/Admin manages the people from it and everybody else reads it, a
     * Viewer included. Which controls are drawn is the screen's business, not
     * the rail's.
     */
    public function testMembersIsOneScreenForEveryMember(): void
    {
        foreach (Role::assignable() as $role) {
            $scope = Scope::forMember(5, false, 1, $role, IsolationMode::Shared);

            self::assertSame('/settings/members', $this->byLabel(
                $this->navigation->forPath($scope, '/')->tools,
                'nav.members',
            )?->href, $role->value);
        }
    }

    /**
     * Profile and Settings are two destinations, and one of them is lit at a
     * time. Standing on Settings must not light the user card as well.
     */
    public function testTheUserCardIsItsOwnDestination(): void
    {
        $onSettings = $this->navigation->forPath($this->owner(), '/settings');

        self::assertFalse($onSettings->account->active, 'Settings is the page; the profile is not.');

        $onProfile = $this->navigation->forPath($this->owner(), '/profile');

        self::assertTrue($onProfile->account->active);
        self::assertFalse($this->byLabel($onProfile->tools, 'nav.settings')?->active);
    }

    /**
     * A Viewer sees the screens they can read and not the ones that would
     * answer 403. Hiding them is a courtesy; the middleware is the control.
     * Members & roles is one they can read.
     */
    public function testAViewerIsNotOfferedWhatTheyMayNotDo(): void
    {
        $labels = $this->allLabels($this->navigation->forPath($this->viewer(), '/'));

        self::assertContains('nav.subscriptions', $labels);
        self::assertContains('nav.notifications', $labels);
        self::assertContains('nav.settings', $labels);
        self::assertContains('nav.members', $labels, 'A Viewer reads the members screen.');
    }

    /**
     * An instance administrator with no household of their own gets the items
     * that need no household, and none of the ones that would be empty.
     */
    public function testSomebodyWithNoHouseholdIsOfferedOnlyWhatExists(): void
    {
        $labels = $this->allLabels($this->navigation->forPath(
            Scope::withoutHousehold(3, true, IsolationMode::Shared),
            '/settings',
        ));

        self::assertSame(['nav.dashboard', 'nav.notifications', 'nav.settings', 'nav.profile'], $labels);
    }

    /**
     * The narrow screen reaches everything the rail does.
     *
     * The tab bar and the More sheet are a split of the same list — the sheet
     * leading with the user card — so this is the assertion that a
     * destination cannot be added to the rail and quietly not exist on a
     * phone. Asserted for every role, since each sees a different list.
     */
    public function testTheNarrowLayoutReachesEveryDestinationTheRailDoes(): void
    {
        $contributor = Scope::forMember(5, false, 1, Role::Contributor, IsolationMode::Isolated);

        foreach ([$this->owner(), $this->viewer(), $contributor] as $scope) {
            $navigation = $this->navigation->forPath($scope, '/');

            $rail = $this->hrefs([...$navigation->primary, ...$navigation->tools, $navigation->account]);
            $narrow = $this->hrefs([...$navigation->tabs, ...$navigation->drawer, $navigation->account]);

            self::assertSame($rail, $narrow);
        }

        $owner = $this->navigation->forPath($this->owner(), '/');

        self::assertSame(['/', '/subscriptions', '/stats'], array_map(
            static fn (NavLink $link): string => $link->href,
            $owner->tabs,
        ), 'The tabs are Home, Subs and Analytics; Add and More sit beside them.');

        self::assertSame(
            ['nav.budgets', 'nav.calendar', 'nav.members', 'nav.notifications', 'nav.settings'],
            $this->labels($owner->drawer),
        );
    }

    /**
     * The tabs that would not fit their rail label carry a short one.
     */
    public function testTheTabsCarryTheirShortLabels(): void
    {
        self::assertSame(
            ['nav.tab_home', 'nav.tab_subscriptions', 'nav.analytics'],
            array_map(
                static fn (NavLink $link): string => $link->tabLabelKey,
                $this->navigation->forPath($this->owner(), '/')->tabs,
            ),
        );
    }

    /**
     * The More sheet says when it holds the page you are on — including the
     * user card at its top — so a narrow screen is not silent about where it
     * is until somebody opens it.
     */
    public function testTheDrawerSaysWhenItHoldsThePageYouAreOn(): void
    {
        self::assertTrue($this->navigation->forPath($this->owner(), '/settings')->drawerHoldsActive());
        self::assertTrue($this->navigation->forPath($this->owner(), '/calendar')->drawerHoldsActive());
        self::assertTrue($this->navigation->forPath($this->owner(), '/profile')->drawerHoldsActive());
        self::assertFalse($this->navigation->forPath($this->owner(), '/')->drawerHoldsActive());
        self::assertFalse($this->navigation->forPath($this->owner(), '/forecast')->drawerHoldsActive());
    }

    private function owner(): Scope
    {
        return Scope::forMember(1, false, 1, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function viewer(): Scope
    {
        return Scope::forMember(2, false, 1, Role::Viewer, IsolationMode::Shared);
    }

    /**
     * @param list<NavLink> $links
     */
    private function byLabel(array $links, string $labelKey): ?NavLink
    {
        foreach ($links as $link) {
            if ($link->labelKey === $labelKey) {
                return $link;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function activeLabels(Navigation $navigation): array
    {
        return $this->labels(array_values(array_filter(
            [...$navigation->primary, ...$navigation->tools, $navigation->account],
            static fn (NavLink $link): bool => $link->active,
        )));
    }

    /**
     * @return list<string>
     */
    private function allLabels(Navigation $navigation): array
    {
        return $this->labels([...$navigation->primary, ...$navigation->tools, $navigation->account]);
    }

    /**
     * @param list<NavLink> $links
     * @return list<string>
     */
    private function labels(array $links): array
    {
        return array_map(static fn (NavLink $link): string => $link->labelKey, $links);
    }

    /**
     * @param list<NavLink> $links
     * @return list<string>
     */
    private function hrefs(array $links): array
    {
        $hrefs = array_map(static fn (NavLink $link): string => $link->href, $links);
        sort($hrefs);

        return $hrefs;
    }
}
