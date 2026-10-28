<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AuditAction;
use App\Domain\CapabilityGrant;
use App\Domain\IsolationMode;
use App\Domain\MembershipStatus;
use App\Domain\Role;
use App\Repository\BudgetRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TokenRepository;
use App\Domain\TokenScopes;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\HouseholdMemberService;
use App\Service\InstanceSettingsService;
use App\Security\RoleMatrix;
use App\Support\Clock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Household member administration, through the real middleware stack.
 *
 * The behaviour worth proving here is the behaviour that is easy to get
 * subtly wrong and impossible to notice: that provisioning does not quietly
 * create a second household, that a revoked login is revoked on every route
 * rather than only the password one, and that a household cannot be left with
 * nobody able to administer it.
 */
final class MemberAdministrationTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private RecordingMailer $mailer;

    private int $ownerId;
    private int $secondOwnerId;
    private int $editorId;
    private int $viewerId;
    private int $outsiderId;
    private int $householdId;
    private int $otherHouseholdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->mailer = new RecordingMailer();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => $this->mailer,
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $verified = new DateTimeImmutable();

        $this->ownerId = $users->create('owner@example.test', 'Olive Owner', 'hash', false, $verified);
        $this->secondOwnerId = $users->create('owner2@example.test', 'Ozzy Owner', 'hash', false, $verified);
        $this->editorId = $users->create('editor@example.test', 'Eddie Editor', 'hash', false, $verified);
        $this->viewerId = $users->create('viewer@example.test', 'Vera Viewer', 'hash', false, $verified);
        $this->outsiderId = $users->create('out@example.test', 'Otto Outsider', 'hash', false, $verified);

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->otherHouseholdId = $households->create('Other household', $this->outsiderId);
        $memberships->create($this->otherHouseholdId, $this->outsiderId, Role::OwnerAdmin);
    }

    // ---------------------------------------------------------------- guards

    /**
     * @return list<array{string, string}>
     */
    public static function memberRoutes(): array
    {
        // Not the list itself: since Phase 26 every member reads it. What
        // they are shown on it is MembersPageTest's.
        return [
            ['GET', '/settings/members/invite'],
            ['POST', '/settings/members'],
            ['POST', '/settings/members/{id}/role'],
            ['POST', '/settings/members/{id}/invite'],
            ['POST', '/settings/members/{id}/reset-password'],
            ['POST', '/settings/members/{id}/revoke'],
            ['POST', '/settings/members/{id}/restore'],
            ['GET', '/settings/members/{id}/remove'],
            ['POST', '/settings/members/{id}/remove'],
        ];
    }

    public function testEveryMemberRouteIsRefusedToAnEditorAContributorAndAViewer(): void
    {
        $contributorId = $this->memberWithRole('contrib@example.test', 'Cody Contributor', Role::Contributor);

        foreach ([$this->editorId, $contributorId, $this->viewerId] as $userId) {
            $this->signIn($userId);

            foreach (self::memberRoutes() as [$method, $path]) {
                $response = $this->request($method, str_replace('{id}', (string) $this->viewerId, $path));

                self::assertSame(
                    403,
                    $response->getStatusCode(),
                    sprintf('%s %s must be refused for a non-Owner.', $method, $path),
                );
            }
        }
    }

    public function testAnOwnerReachesTheList(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request('GET', '/settings/members');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Eddie Editor', (string) $response->getBody());
    }

    /**
     * Each new screen rendered for real.
     *
     * A page that is only ever reached by a test asserting 403, or by a POST
     * that redirects, is a page no test has actually drawn — and the way a
     * Twig template fails is a 500 on a missing translation key, in
     * production, on the one screen nobody opened.
     */
    public function testEveryNewScreenRenders(): void
    {
        $this->signIn($this->ownerId);

        $removal = $this->request('GET', '/settings/members/' . $this->editorId . '/remove');
        self::assertSame(200, $removal->getStatusCode(), (string) $removal->getBody());
        self::assertStringContainsString('Eddie Editor', (string) $removal->getBody());

        // And the ISOLATED variant, which is the branch that asks the question
        // rather than stating what will happen.
        $this->setIsolated();
        $this->subscriptionOwnedBy($this->editorId);

        $isolated = $this->request('GET', '/settings/members/' . $this->editorId . '/remove');
        self::assertSame(200, $isolated->getStatusCode(), (string) $isolated->getBody());
        self::assertStringContainsString('type="radio"', (string) $isolated->getBody());
    }

    /**
     * Every assignable role reaches the inline role select on the members
     * screen, which is the only form that can hand somebody Owner.
     *
     * Read off the enum rather than written out, so a role added to
     * `Role::assignable()` and forgotten in a template fails here. The failure
     * it exists for is the quiet one: a new role that works everywhere except
     * the only form that can hand it to anybody.
     */
    public function testEveryAssignableRoleIsOfferedOnTheMembersScreen(): void
    {
        $this->signIn($this->ownerId);

        $html = (string) $this->request('GET', '/settings/members')->getBody();

        foreach (Role::assignable() as $role) {
            self::assertStringContainsString(
                sprintf('value="%s"', $role->value),
                $html,
                sprintf('The members screen offers no way to pick %s.', $role->value),
            );
        }

        self::assertStringContainsString('Contributor', $html, 'The role has no label on the screen.');
    }

    /**
     * And a role picked in that form is the role the member ends up with.
     */
    public function testSomebodyCanBeAddedAsAContributor(): void
    {
        $this->signIn($this->ownerId);

        $this->request('POST', '/settings/members', [
            'display_name' => 'Bram',
            'email' => 'bram@example.test',
            'role' => Role::Contributor->value,
        ]);

        $added = null;
        foreach ((new MembershipRepository($this->db))->listMembers($this->householdId) as $member) {
            if ($member->email === 'bram@example.test') {
                $added = $member;
            }
        }

        self::assertNotNull($added, 'The member was not added.');
        self::assertSame(Role::Contributor, $added->role);
    }

    public function testTheInviteFormRenders(): void
    {
        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members', [
            'display_name' => 'Sam Child',
            'email' => 'sam@example.test',
            'role' => Role::Viewer->value,
        ]);

        $token = $this->inviteLinkToken();
        $this->session->clear();

        // Every placeholder in the subject filled, the inviter's name above all.
        $message = end($this->mailer->messages);
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
        self::assertSame('Olive Owner has invited you to Renovo', $message->getSubject());

        $form = $this->request('GET', '/accept-invite?token=' . $token);
        self::assertSame(200, $form->getStatusCode(), (string) $form->getBody());
        self::assertStringContainsString('name="password"', (string) $form->getBody());

        // And the page a spent or forged link lands on.
        $expired = $this->request('GET', '/accept-invite?token=deadbeef');
        self::assertSame(410, $expired->getStatusCode(), (string) $expired->getBody());
    }

    public function testTheFailedEmailChangePageRenders(): void
    {
        $response = $this->request('GET', '/confirm-email-change?token=deadbeef');

        self::assertSame(410, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('link', strtolower((string) $response->getBody()));
    }

    // ----------------------------------------------------------- provisioning

    public function testAddingAMemberJoinsThisHouseholdAndCreatesNoOther(): void
    {
        $before = $this->countHouseholds();

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members', [
            'display_name' => 'Sam Child',
            'email' => 'sam@example.test',
            'role' => Role::Viewer->value,
        ]);

        self::assertSame($before, $this->countHouseholds(), 'Provisioning must not create a household.');

        $member = (new UserRepository($this->db))->findByEmail('sam@example.test');
        self::assertNotNull($member);

        $membership = (new MembershipRepository($this->db))
            ->findForUserAndHousehold($member->id, $this->householdId);

        self::assertNotNull($membership, 'The new member must be in the adding Owner\'s household.');
        self::assertSame(Role::Viewer, $membership->role);
        self::assertSame(MembershipStatus::Pending, $membership->status);
        self::assertSame([$this->householdId], array_map(
            static fn ($m): int => $m->householdId,
            (new MembershipRepository($this->db))->findAllForUser($member->id),
        ));
    }

    public function testAnInvitedMemberSetsTheirOwnPasswordAndIsThenActive(): void
    {
        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members', [
            'display_name' => 'Sam Child',
            'email' => 'sam@example.test',
            'role' => Role::Editor->value,
        ]);

        $users = new UserRepository($this->db);
        $member = $users->findByEmail('sam@example.test');
        self::assertNotNull($member);
        self::assertFalse($member->isVerified(), 'An invited member is unverified until they accept.');

        $token = $this->inviteLinkToken();
        self::assertNotSame('', $token);

        $this->session->clear();
        $response = $this->request('POST', '/accept-invite', [
            'token' => $token,
            'password' => 'a-password-they-chose',
            'password_confirm' => 'a-password-they-chose',
        ]);

        self::assertSame(302, $response->getStatusCode());

        $member = $users->findById($member->id);
        self::assertNotNull($member);
        self::assertTrue($member->isVerified());
        self::assertTrue((new PasswordHasher())->verify('a-password-they-chose', $member->passwordHash));

        $membership = (new MembershipRepository($this->db))
            ->findForUserAndHousehold($member->id, $this->householdId);
        self::assertNotNull($membership);
        self::assertSame(MembershipStatus::Active, $membership->status);
    }

    public function testAnInviteCannotBeAcceptedTwice(): void
    {
        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members', [
            'display_name' => 'Sam Child',
            'email' => 'sam@example.test',
            'role' => Role::Editor->value,
        ]);

        $token = $this->inviteLinkToken();
        $this->session->clear();

        $body = ['token' => $token, 'password' => 'first-password-x', 'password_confirm' => 'first-password-x'];
        self::assertSame(302, $this->request('POST', '/accept-invite', $body)->getStatusCode());

        $second = ['token' => $token, 'password' => 'second-password', 'password_confirm' => 'second-password'];
        self::assertSame(410, $this->request('POST', '/accept-invite', $second)->getStatusCode());

        $member = (new UserRepository($this->db))->findByEmail('sam@example.test');
        self::assertNotNull($member);
        self::assertTrue(
            (new PasswordHasher())->verify('first-password-x', $member->passwordHash),
            'The spent link must not be able to set a second password.',
        );
    }

    public function testAMemberWithNoMailboxGetsAHashedTemporaryPasswordAndMustReplaceIt(): void
    {
        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members', [
            'display_name' => 'Sam Child',
            'email' => '',
            'without_email' => '1',
            'role' => Role::Viewer->value,
        ]);

        $temporary = $this->session->get('member_temporary_password');
        self::assertIsString($temporary);
        self::assertNotSame('', $temporary);

        $member = $this->memberNamed('Sam Child');

        // Stored as a hash like any other password, never in the clear.
        self::assertNotSame($temporary, $member->passwordHash);
        self::assertTrue((new PasswordHasher())->verify($temporary, $member->passwordHash));
        self::assertTrue($member->mustChangePassword);
        self::assertFalse($member->canReceiveMail());
        self::assertTrue($member->isVerified(), 'There is no address to verify, so they must not be blocked.');

        self::assertTrue($this->hasAudit(AuditAction::MemberTemporaryPasswordIssued, $member->id));
    }

    /**
     * The account page and the preferences page were merged, so the screen a
     * member on a temporary password is held on is `/profile`. That path is
     * allowed *exactly* and not as a prefix, which is the assertion below
     * about `/profile/preferences`: were it a prefix, being locked out would
     * quietly grant every route under it.
     */
    public function testAMemberOnATemporaryPasswordIsHeldOnTheProfilePage(): void
    {
        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members', [
            'display_name' => 'Sam Child',
            'email' => '',
            'without_email' => '1',
            'role' => Role::Editor->value,
        ]);

        $temporary = (string) $this->session->get('member_temporary_password');
        $member = $this->memberNamed('Sam Child');

        $this->signIn($member->id);

        $diverted = $this->request('GET', '/subscriptions');
        self::assertSame(302, $diverted->getStatusCode());
        self::assertSame('/profile', $diverted->getHeaderLine('Location'));

        $page = $this->request('GET', '/profile');
        self::assertSame(200, $page->getStatusCode(), substr((string) $page->getBody(), 0, 2000));

        // The page is the callout and the password form, and nothing else:
        // every other card on it posts to a route this member cannot reach,
        // and a form that throws them back here is worse than one that waits.
        $body = (string) $page->getBody();
        self::assertStringContainsString('/profile/password', $body);
        self::assertStringNotContainsString('/profile/preferences', $body);
        self::assertStringNotContainsString('/profile/email', $body);

        // The routes under the page stay shut while the flag is set.
        $preferences = $this->request('POST', '/profile/preferences', ['theme' => 'dark']);
        self::assertSame(302, $preferences->getStatusCode());
        self::assertSame('/profile', $preferences->getHeaderLine('Location'));

        $this->request('POST', '/profile/password', [
            'current_password' => $temporary,
            'password' => 'a-password-of-my-own',
            'password_confirm' => 'a-password-of-my-own',
        ]);

        $updated = (new UserRepository($this->db))->findById($member->id);
        self::assertNotNull($updated);
        self::assertFalse($updated->mustChangePassword);

        self::assertSame(200, $this->request('GET', '/subscriptions')->getStatusCode());
    }

    // -------------------------------------------------------------- revoking

    public function testRevokingALoginEndsEverySessionAndRefusesEveryWayBackIn(): void
    {
        $token = $this->giveEditorASessionAndAToken();

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/revoke');

        $member = (new UserRepository($this->db))->findById($this->editorId);
        self::assertNotNull($member);
        self::assertTrue($member->isDisabled());

        self::assertSame(0, $this->countSessionsFor($this->editorId), 'Every session must be gone at once.');

        // The password route says so in words, having first proved the password.
        $this->session->clear();
        $response = $this->request('POST', '/login', [
            'email' => 'editor@example.test',
            'password' => 'editor-password',
        ]);
        self::assertSame(422, $response->getStatusCode());

        // And an API token issued before the revocation stops working, which a
        // check in the password path alone would have missed entirely.
        self::assertSame(401, $this->apiRequest('GET', '/api/v1/me', $token)->getStatusCode());

        self::assertTrue($this->hasAudit(AuditAction::MemberLoginRevoked, $this->editorId));
    }

    public function testRestoringALoginLetsThemBackIn(): void
    {
        $token = $this->giveEditorASessionAndAToken();

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/revoke');
        $this->request('POST', '/settings/members/' . $this->editorId . '/restore');

        $member = (new UserRepository($this->db))->findById($this->editorId);
        self::assertNotNull($member);
        self::assertFalse($member->isDisabled());

        $this->session->clear();
        $response = $this->request('POST', '/login', [
            'email' => 'editor@example.test',
            'password' => 'editor-password',
        ]);
        self::assertSame(302, $response->getStatusCode());

        self::assertSame(200, $this->apiRequest('GET', '/api/v1/me', $token)->getStatusCode());
        self::assertTrue($this->hasAudit(AuditAction::MemberLoginRestored, $this->editorId));
    }

    // ------------------------------------------------------------ last Owner

    public function testTheLastOwnerCannotBeDemotedRevokedOrRemoved(): void
    {
        // A second Owner, so the acting Owner is not refused merely for being
        // the person doing it — the guard under test is about the household's
        // supply of Owners, not about acting on yourself.
        $this->signIn($this->secondOwnerId);
        (new MembershipRepository($this->db))->create($this->householdId, $this->secondOwnerId, Role::OwnerAdmin);

        // Two Owners: demoting one is allowed.
        $this->request('POST', '/settings/members/' . $this->ownerId . '/role', ['role' => Role::Editor->value]);
        self::assertSame(Role::Editor, $this->roleOf($this->ownerId));

        // One Owner left, and it is the person acting — refused three ways.
        $this->signIn($this->ownerId);
        (new MembershipRepository($this->db))->updateRole($this->householdId, $this->ownerId, Role::OwnerAdmin);
        (new MembershipRepository($this->db))->updateRole($this->householdId, $this->secondOwnerId, Role::Editor);

        $this->request('POST', '/settings/members/' . $this->ownerId . '/role', ['role' => Role::Viewer->value]);
        self::assertSame(Role::OwnerAdmin, $this->roleOf($this->ownerId));

        // And refused to a second Owner acting on the last one, once they are
        // the only Owner the household has.
        $this->signIn($this->secondOwnerId);
        (new MembershipRepository($this->db))->updateRole($this->householdId, $this->secondOwnerId, Role::OwnerAdmin);
        (new MembershipRepository($this->db))->updateRole($this->householdId, $this->ownerId, Role::Editor);

        // Now `secondOwner` is the only Owner; the guard refuses their own
        // demotion via the self check, and refuses the removal of an Owner who
        // is the last one. Make `owner` the last Owner again to test removal.
        (new MembershipRepository($this->db))->updateRole($this->householdId, $this->secondOwnerId, Role::Editor);
        (new MembershipRepository($this->db))->updateRole($this->householdId, $this->ownerId, Role::OwnerAdmin);

        $this->signIn($this->secondOwnerId);
        $this->request('POST', '/settings/members/' . $this->ownerId . '/revoke');
        $stillHere = (new UserRepository($this->db))->findById($this->ownerId);
        self::assertNotNull($stillHere);
        self::assertFalse($stillHere->isDisabled(), 'Revoking the last Owner would lock the household out.');

        $this->request('POST', '/settings/members/' . $this->ownerId . '/remove', ['data' => 'reassign']);
        self::assertNotNull(
            (new MembershipRepository($this->db))->findForUserAndHousehold($this->ownerId, $this->householdId),
            'Removing the last Owner would lock the household out.',
        );
    }

    // --------------------------------------------------------------- removal

    public function testRemovingAMemberInSharedModeKeepsTheirRowsAndLeavesNoDanglingOwner(): void
    {
        $subscriptionId = $this->subscriptionOwnedBy($this->editorId);

        // A budget too, and one the Owner also has a counterpart for. Every
        // table carrying an `owner_user_id` has to be moved, not just the one
        // the screen happens to show — a budget left behind is a foreign key
        // pointing at somebody who is no longer in the household. This one
        // measures the household, so it outlives them; one measuring *them*
        // does not (see the next test).
        $theirBudget = $this->budgetOwnedBy($this->editorId, 'Theirs', null);
        $this->budgetOwnedBy($this->ownerId, 'Mine');

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', ['data' => 'reassign']);

        self::assertNull(
            (new MembershipRepository($this->db))->findForUserAndHousehold($this->editorId, $this->householdId),
        );

        self::assertSame(
            $this->ownerId,
            $this->ownerOf($subscriptionId),
            'The row stays with the household and points at somebody who is still in it.',
        );

        self::assertSame(
            $this->ownerId,
            $this->ownerOfBudget($theirBudget),
            'Their budget moves with everything else, even though the Owner has one of their own.',
        );

        self::assertSame(0, $this->rowsOwnedBy($this->editorId), 'Nothing may still name them.');

        self::assertTrue($this->hasAudit(AuditAction::MemberRemoved, $this->editorId));
    }

    public function testBudgetsMeasuringTheDepartedMemberGoWithThemWhoeverSetThem(): void
    {
        $theirOwn = $this->budgetOwnedBy($this->editorId, 'Their own');
        $setForThem = $this->budgetOwnedBy($this->ownerId, 'Set for them', $this->editorId);
        $ownersOwn = $this->budgetOwnedBy($this->ownerId, 'Mine');

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', ['data' => 'reassign']);

        self::assertNull($this->ownerOfBudget($theirOwn), 'A budget over nobody\'s spending measures nothing.');
        self::assertNull($this->ownerOfBudget($setForThem));
        self::assertSame($this->ownerId, $this->ownerOfBudget($ownersOwn));
    }

    public function testRemovingAMemberClearsThemAsPayer(): void
    {
        // A shared row the Owner owns, that the editor pays for, and one the
        // editor owns and pays for: neither may keep naming them (K49).
        $ownersRow = $this->subscriptionOwnedBy($this->ownerId);
        $editorsRow = $this->subscriptionOwnedBy($this->editorId);
        $paidByOwner = $this->subscriptionOwnedBy($this->ownerId);
        $payers = [$ownersRow => $this->editorId, $editorsRow => $this->editorId, $paidByOwner => $this->ownerId];
        foreach ($payers as $id => $payer) {
            $this->db->execute(
                'UPDATE ' . $this->q('subscriptions') . ' SET ' . $this->q('payer_user_id') . ' = :payer'
                . ' WHERE ' . $this->q('id') . ' = :id',
                ['payer' => $payer, 'id' => $id],
            );
        }

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', ['data' => 'reassign']);

        self::assertNull($this->payerOf($ownersRow));
        self::assertNull($this->payerOf($editorsRow));
        self::assertSame($this->ownerId, $this->payerOf($paidByOwner), 'Somebody still here keeps their name.');
    }

    public function testRemovingAMemberWithTheirDataDeletedStillClearsThemAsPayer(): void
    {
        $this->setIsolated();
        $ownersRow = $this->subscriptionOwnedBy($this->ownerId);
        $this->db->execute(
            'UPDATE ' . $this->q('subscriptions') . ' SET ' . $this->q('payer_user_id') . ' = :payer'
            . ' WHERE ' . $this->q('id') . ' = :id',
            ['payer' => $this->editorId, 'id' => $ownersRow],
        );
        $this->subscriptionOwnedBy($this->editorId);

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', ['data' => 'delete']);

        self::assertNull($this->payerOf($ownersRow));
        self::assertSame($this->ownerId, $this->ownerOf($ownersRow), 'The Owner keeps their own row.');
    }

    public function testASharedRemovalAsksAboutPrivateSubscriptionsAndCanDeleteThem(): void
    {
        $public = $this->subscriptionOwnedBy($this->editorId);
        $private = $this->subscriptionOwnedBy($this->editorId, private: true);

        $this->signIn($this->ownerId);
        $page = (string) $this->request('GET', '/settings/members/' . $this->editorId . '/remove')->getBody();
        self::assertStringContainsString('name="private_data"', $page, 'nobody else has seen these, so ask');

        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', [
            'data' => 'reassign',
            'private_data' => 'delete',
        ]);

        self::assertSame($this->ownerId, $this->ownerOf($public));
        self::assertNull($this->ownerOf($private));
        self::assertSame(0, $this->rowsOwnedBy($this->editorId));
    }

    public function testASharedRemovalThatReassignsAPrivateSubscriptionKeepsItPrivate(): void
    {
        $private = $this->subscriptionOwnedBy($this->editorId, private: true);
        $this->db->execute(
            'UPDATE ' . $this->q('subscriptions') . ' SET ' . $this->q('payer_user_id') . ' = :payer'
            . ' WHERE ' . $this->q('id') . ' = :id',
            ['payer' => $this->editorId, 'id' => $private],
        );

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', [
            'data' => 'reassign',
            'private_data' => 'reassign',
        ]);

        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->q('subscriptions') . ' WHERE ' . $this->q('id') . ' = :id',
            ['id' => $private],
        );
        self::assertNotNull($row);
        self::assertSame($this->ownerId, (int) $row['owner_user_id']);
        self::assertSame('payer', $row['visibility'], 'private to its new owner, not published');
        self::assertNull($row['payer_user_id'], 'a private row is paid by its owner or nobody named');
    }

    public function testARemovedMembersApiTokenNoLongerReachesTheHousehold(): void
    {
        $token = $this->giveEditorASessionAndAToken();
        $this->subscriptionOwnedBy($this->ownerId);

        self::assertSame(200, $this->apiRequest('GET', '/api/v1/subscriptions', $token)->getStatusCode());

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', ['data' => 'reassign']);

        // The token row is untouched and still names this household, and it
        // still authenticates — the account was not disabled, only removed. It
        // reaches nothing, because `ScopeFactory` builds the scope from live
        // memberships and ignores a preference the holder no longer has: with
        // no membership left they get a scope with no household, which has no
        // role and therefore cannot even read.
        self::assertSame(
            403,
            $this->apiRequest('GET', '/api/v1/subscriptions', $token)->getStatusCode(),
            'A token naming a household its holder has left must not reach it.',
        );
    }

    public function testRemovingAMemberInIsolatedModeCanDeleteTheirRowsInstead(): void
    {
        $this->setIsolated();
        $subscriptionId = $this->subscriptionOwnedBy($this->editorId);

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', ['data' => 'delete']);

        self::assertNull($this->ownerOf($subscriptionId), 'The row was to be deleted, not reassigned.');
    }

    public function testRemovingAMemberInIsolatedModeCanReassignTheirRowsTheAdminCannotSee(): void
    {
        $this->setIsolated();
        $subscriptionId = $this->subscriptionOwnedBy($this->editorId);

        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', ['data' => 'reassign']);

        self::assertSame(
            $this->ownerId,
            $this->ownerOf($subscriptionId),
            'A private row the Owner could not read still has to be moved, or it is orphaned.',
        );
    }

    public function testAnOwnerCannotAdministerAMemberOfAnotherHousehold(): void
    {
        $this->signIn($this->ownerId);

        $this->request('POST', '/settings/members/' . $this->outsiderId . '/revoke');

        $outsider = (new UserRepository($this->db))->findById($this->outsiderId);
        self::assertNotNull($outsider);
        self::assertFalse($outsider->isDisabled());
    }

    // --------------------------------------------------------------- helpers

    // ------------------------------------------------ Phase 26: the screen

    /**
     * Everybody in the household reads the list; only an Owner/Admin is drawn
     * anything that changes it. The routes behind the controls are refused to
     * the others regardless — see testEveryMemberRouteIsRefusedToAnEditorAContributorAndAViewer.
     */
    public function testEveryMemberReadsTheListAndOnlyAnOwnerIsDrawnControls(): void
    {
        $contributorId = $this->memberWithRole('contributor@example.test', 'Cora Contributor', Role::Contributor);

        foreach ([$this->editorId, $contributorId, $this->viewerId] as $userId) {
            $this->signIn($userId);
            $html = (string) $this->request('GET', '/settings/members')->getBody();

            self::assertStringContainsString('Olive Owner', $html);
            self::assertStringNotContainsString('action="/settings/members', $html, 'A management form was drawn.');
            self::assertStringNotContainsString('href="/settings/members/', $html, 'A management link was drawn.');
            self::assertStringNotContainsString('id="member-dialog"', $html);
            self::assertStringNotContainsString('Invite member', $html);
        }

        $this->signIn($this->ownerId);
        $html = (string) $this->request('GET', '/settings/members')->getBody();

        self::assertStringContainsString('Invite member', $html);
        self::assertStringContainsString('action="/settings/members/' . $this->editorId . '/role"', $html);
        self::assertStringContainsString('href="/settings/members/' . $this->editorId . '/remove"', $html);
    }

    /**
     * Not on your own row: the service refuses you your own role and your own
     * removal, so the controls would only be errors.
     */
    public function testAnOwnerIsDrawnNoControlsOnTheirOwnRow(): void
    {
        $this->signIn($this->ownerId);
        $html = (string) $this->request('GET', '/settings/members')->getBody();

        self::assertStringNotContainsString('/settings/members/' . $this->ownerId . '/', $html);
        self::assertStringContainsString('· you', $html);
    }

    /**
     * The inline select is one more way to ask for a demotion, and the answer
     * for the last Owner is the same as ever — whether it comes from htmx or
     * from the no-script Save button.
     */
    public function testTheLastOwnerCannotBeDemotedThroughTheInlineSelect(): void
    {
        $this->signIn($this->ownerId);

        foreach ([[], ['HX-Request' => 'true']] as $headers) {
            $this->request('POST', '/settings/members/' . $this->ownerId . '/role', ['role' => 'viewer'], $headers);

            self::assertSame(Role::OwnerAdmin, $this->roleOf($this->ownerId));
        }

        // The change it is for still works, and says so from htmx.
        $response = $this->request(
            'POST',
            '/settings/members/' . $this->editorId . '/role',
            ['role' => 'contributor'],
            ['HX-Request' => 'true'],
        );

        self::assertSame('/settings/members', $response->getHeaderLine('HX-Redirect'));
        self::assertSame(Role::Contributor, $this->roleOf($this->editorId));
        self::assertTrue($this->hasAudit(AuditAction::RoleChanged, $this->editorId));
    }

    /**
     * Nobody arrives as an Owner: the form does not offer it, and a request
     * that asks anyway creates nobody.
     */
    public function testAnInviteCannotCreateAnOwner(): void
    {
        $this->signIn($this->ownerId);

        $form = (string) $this->request('GET', '/settings/members/invite')->getBody();
        self::assertStringNotContainsString('value="owner_admin"', $form);
        foreach ([Role::Editor, Role::Contributor, Role::Viewer] as $role) {
            self::assertStringContainsString('value="' . $role->value . '"', $form);
        }

        $response = $this->request('POST', '/settings/members', [
            'display_name' => 'Pat Pretender',
            'email' => 'pat@example.test',
            'role' => Role::OwnerAdmin->value,
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse((new UserRepository($this->db))->emailExists('pat@example.test'));
        self::assertSame([], $this->mailer->messages, 'An invitation went out for a refused member.');
    }

    /**
     * The dialog says how long the link lasts, and it says the token's own
     * lifetime rather than a number of its own.
     */
    public function testTheInviteExpiryIsTheTokenLifetime(): void
    {
        $days = HouseholdMemberService::INVITE_LIFETIME_DAYS;
        $this->signIn($this->ownerId);

        $fragment = $this->request('GET', '/settings/members/invite', [], ['HX-Request' => 'true']);
        self::assertSame(200, $fragment->getStatusCode());
        self::assertStringContainsString(sprintf('expires in %d days', $days), (string) $fragment->getBody());
        self::assertStringNotContainsString(
            '<html',
            (string) $fragment->getBody(),
            'The dialog was sent a whole page.',
        );

        $this->request('POST', '/settings/members', [
            'display_name' => 'Nia New',
            'email' => 'nia@example.test',
            'role' => Role::Viewer->value,
        ]);

        $expires = $this->db->fetchValue(
            'SELECT ' . $this->q('expires_at') . ' FROM ' . $this->q('auth_tokens')
            . ' WHERE ' . $this->q('purpose') . ' = :purpose',
            ['purpose' => TokenRepository::PURPOSE_INVITE],
        );
        self::assertIsString($expires);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $expected = $container->get(Clock::class)->now()->modify('+' . $days . ' days');

        self::assertEqualsWithDelta(
            $expected->getTimestamp(),
            (new DateTimeImmutable($expires, $expected->getTimezone()))->getTimestamp(),
            120,
        );
    }

    /**
     * Rows nobody else has seen are never disposed of on a default: in SHARED
     * that is their private subscriptions, and a removal that leaves the
     * question unanswered is refused and removes nobody.
     */
    public function testASharedRemovalOfAMemberWithPrivateRowsRequiresAChoice(): void
    {
        $private = $this->subscriptionOwnedBy($this->editorId, private: true);
        $this->signIn($this->ownerId);

        $page = (string) $this->request('GET', '/settings/members/' . $this->editorId . '/remove')->getBody();
        self::assertStringNotContainsString('checked', $page, 'An answer was chosen in advance.');

        $response = $this->request('POST', '/settings/members/' . $this->editorId . '/remove', ['data' => 'reassign']);

        self::assertSame('/settings/members/' . $this->editorId . '/remove', $response->getHeaderLine('Location'));
        self::assertNotNull($this->roleOf($this->editorId), 'The member was removed without the question answered.');
        self::assertSame($this->editorId, $this->ownerOf($private));

        $this->request('POST', '/settings/members/' . $this->editorId . '/remove', [
            'data' => 'reassign',
            'private_data' => 'reassign',
        ]);

        self::assertNull($this->roleOf($this->editorId));
        self::assertSame($this->ownerId, $this->ownerOf($private), 'Reassigned, not orphaned.');
    }

    public function testAnIsolatedRemovalOfAMemberWithRowsRequiresAChoice(): void
    {
        $this->setIsolated();
        $theirs = $this->subscriptionOwnedBy($this->editorId);
        $this->signIn($this->ownerId);

        $this->request('POST', '/settings/members/' . $this->editorId . '/remove');

        self::assertNotNull($this->roleOf($this->editorId));
        self::assertSame($this->editorId, $this->ownerOf($theirs));
    }

    /**
     * A member with nothing unseen is removed without a question, as before.
     */
    public function testARemovalWithNothingToAskNeedsNoAnswer(): void
    {
        $this->subscriptionOwnedBy($this->editorId);
        $this->signIn($this->ownerId);

        $this->request('POST', '/settings/members/' . $this->editorId . '/remove');

        self::assertNull($this->roleOf($this->editorId));
    }

    /**
     * Every action on the screen leaves its entry in the audit log, attributed
     * to whoever took it and naming whom it was taken against.
     */
    public function testEveryActionOnTheScreenWritesItsAuditEntry(): void
    {
        $this->signIn($this->ownerId);

        $this->request('POST', '/settings/members', [
            'display_name' => 'Ivy Invitee',
            'email' => 'ivy@example.test',
            'role' => Role::Viewer->value,
        ]);
        $invitee = (new UserRepository($this->db))->findByEmail('ivy@example.test');
        self::assertNotNull($invitee);

        $steps = [
            [AuditAction::MemberAdded, $invitee->id, null],
            [AuditAction::MemberInviteResent, $invitee->id, '/invite'],
            [AuditAction::MemberPasswordResetSent, $this->editorId, '/reset-password'],
            [AuditAction::MemberLoginRevoked, $this->editorId, '/revoke'],
            [AuditAction::MemberLoginRestored, $this->editorId, '/restore'],
            [AuditAction::RoleChanged, $this->editorId, '/role'],
            [AuditAction::MemberRemoved, $this->editorId, '/remove'],
        ];

        foreach ($steps as [$action, $target, $path]) {
            if ($path !== null) {
                $this->request('POST', '/settings/members/' . $target . $path, ['role' => 'viewer']);
            }

            self::assertTrue($this->hasAudit($action, $target), $action->value . ' was not audited.');
        }
    }

    /**
     * The table on the page is RoleMatrix's, cell for cell, in both modes —
     * and RoleMatrix is PermissionService's (see RoleMatrixTest), so what the
     * page claims is what the routes enforce.
     */
    public function testTheRoleTableOnThePageIsTheGeneratedOne(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $matrix = $container->get(RoleMatrix::class);

        foreach ([IsolationMode::Shared, IsolationMode::Isolated] as $mode) {
            $container->get(InstanceSettingsService::class)->setIsolationMode($mode);
            $this->signIn($this->viewerId);
            $html = (string) $this->request('GET', '/settings/members')->getBody();

            foreach ($matrix->rows($mode) as $row) {
                $start = strpos($html, 'data-capability="' . $row['capability']->value . '"');
                self::assertNotFalse($start, $row['capability']->value . ' is missing from the page.');
                $markup = substr($html, $start, (int) strpos($html, '</tr>', $start) - $start);

                foreach ($row['cells'] as $cell) {
                    self::assertMatchesRegularExpression(
                        sprintf(
                            '/class="grant grant-%s" data-role="%s">\s*<svg[^>]*>.*?<\/svg>\s*'
                            . '<span class="grant-label%s">%s<\/span>/s',
                            $cell['grant']->value,
                            $cell['role']->value,
                            $cell['grant'] === CapabilityGrant::OwnOnly ? '' : ' visually-hidden',
                            preg_quote($this->grantWord($cell['grant']), '/'),
                        ),
                        $markup,
                        sprintf('%s / %s in %s', $row['capability']->value, $cell['role']->value, $mode->value),
                    );
                }
            }
        }
    }

    /**
     * The visibility card states the mode and does not offer to change it,
     * except to the one person who may: an instance administrator, who is
     * sent to the instance setting.
     */
    public function testTheVisibilityCardIsReadOnlyAndLinksOnlyAnInstanceAdminToTheSetting(): void
    {
        $this->signIn($this->ownerId);
        $html = (string) $this->request('GET', '/settings/members')->getBody();

        self::assertStringContainsString('data-visibility-mode">Shared<', $html);
        self::assertStringContainsString('hidden from everyone else in either mode', $html);
        self::assertStringNotContainsString('name="isolation_mode"', $html);
        self::assertStringNotContainsString('href="/settings/instance#data-isolation"', $html);

        $adminId = $this->memberWithRole('admin@example.test', 'Ada Admin', Role::Editor, instanceAdmin: true);
        $this->setIsolated();
        $this->signIn($adminId);
        $html = (string) $this->request('GET', '/settings/members')->getBody();

        self::assertStringContainsString('data-visibility-mode">Isolated<', $html);
        self::assertStringContainsString('href="/settings/instance#data-isolation"', $html);
    }

    /**
     * A private subscription counts in its payer's share and nobody else's
     * view of it: the Owner, who cannot see the row, is not shown its cost in
     * the Editor's share, and the Editor is.
     */
    public function testMonthlyShareLeavesOutPrivateRowsForEveryoneButThePayer(): void
    {
        $this->subscriptionOwnedBy($this->editorId, private: true);

        $this->signIn($this->ownerId);
        self::assertStringNotContainsString('£5.00', (string) $this->request('GET', '/settings/members')->getBody());

        $this->signIn($this->editorId);
        self::assertStringContainsString('£5.00', (string) $this->request('GET', '/settings/members')->getBody());
    }

    public function testEachStatusIsDrawnInWordsAsWellAsColour(): void
    {
        $this->signIn($this->ownerId);
        $this->request('POST', '/settings/members', [
            'display_name' => 'Pip Pending',
            'email' => 'pip@example.test',
            'role' => Role::Viewer->value,
        ]);
        $this->request('POST', '/settings/members/' . $this->editorId . '/revoke');

        $html = (string) $this->request('GET', '/settings/members')->getBody();

        self::assertStringContainsString('<span class="badge badge-ok">Active</span>', $html);
        self::assertStringContainsString('<span class="badge badge-warn">Invite pending</span>', $html);
        self::assertStringContainsString('<span class="badge badge-bad">Login revoked</span>', $html);
    }

    public function testAnOwnerCannotRevokeAnInstanceAdmin(): void
    {
        $adminId = $this->addInstanceAdminOwner();

        $this->signIn($this->ownerId);
        $page = (string) $this->request('GET', '/settings/members')->getBody();
        self::assertStringNotContainsString('/settings/members/' . $adminId . '/revoke', $page);
        self::assertStringContainsString('/settings/members/' . $this->editorId . '/revoke', $page);

        $this->request('POST', '/settings/members/' . $adminId . '/revoke');

        $admin = (new UserRepository($this->db))->findById($adminId);
        self::assertNotNull($admin);
        self::assertFalse($admin->isDisabled(), 'A household Owner must not be able to disable an instance admin.');
    }

    public function testAnInstanceAdminMayRevokeAnotherOwner(): void
    {
        $adminId = $this->addInstanceAdminOwner();

        $this->signIn($adminId);
        $page = (string) $this->request('GET', '/settings/members')->getBody();
        self::assertStringContainsString('/settings/members/' . $this->ownerId . '/revoke', $page);

        $this->request('POST', '/settings/members/' . $this->ownerId . '/revoke');

        $owner = (new UserRepository($this->db))->findById($this->ownerId);
        self::assertNotNull($owner);
        self::assertTrue($owner->isDisabled());
    }

    private function addInstanceAdminOwner(): int
    {
        $adminId = (new UserRepository($this->db))->create(
            'root@example.test',
            'Rhea Root',
            'hash',
            true,
            new DateTimeImmutable(),
        );
        (new MembershipRepository($this->db))->create($this->householdId, $adminId, Role::OwnerAdmin);

        return $adminId;
    }

    private function grantWord(CapabilityGrant $grant): string
    {
        return match ($grant) {
            CapabilityGrant::Yes => 'Yes',
            CapabilityGrant::OwnOnly => 'Own only',
            CapabilityGrant::No => 'No',
        };
    }

    private function memberWithRole(string $email, string $name, Role $role, bool $instanceAdmin = false): int
    {
        $userId = (new UserRepository($this->db))
            ->create($email, $name, 'hash', $instanceAdmin, new DateTimeImmutable());
        (new MembershipRepository($this->db))->create($this->householdId, $userId, $role);

        return $userId;
    }

    private function setIsolated(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $container->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);
    }

    private function subscriptionOwnedBy(int $userId, bool $private = false): int
    {
        $scope = Scope::forMember($userId, false, $this->householdId, Role::Editor, IsolationMode::Shared);

        return (new SubscriptionRepository($this->db))->create($scope, [
            'name' => 'Theirs',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
            'visibility' => $private ? 'payer' : 'household',
        ], []);
    }

    /**
     * @param int|null|false $subjectUserId Whose spending it measures: false
     *                                      (the default) for the owner, null
     *                                      for the household.
     */
    private function budgetOwnedBy(int $userId, string $name, int|null|false $subjectUserId = false): int
    {
        $scope = Scope::forMember($userId, false, $this->householdId, Role::Editor, IsolationMode::Shared);

        return (new BudgetRepository($this->db))->create($scope, [
            'name' => $name,
            'period' => 'monthly',
            'amount_minor' => 10000,
            'currency' => 'GBP',
            'is_active' => true,
            'subject_user_id' => $subjectUserId === false ? $userId : $subjectUserId,
        ]);
    }

    private function ownerOfBudget(int $budgetId): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->q('owner_user_id') . ' FROM ' . $this->q('budgets')
            . ' WHERE ' . $this->q('id') . ' = :id',
            ['id' => $budgetId],
        );

        return $row === null ? null : (int) $row['owner_user_id'];
    }

    /**
     * Rows in any household table that still name this member as their owner.
     */
    private function rowsOwnedBy(int $userId): int
    {
        $total = 0;

        foreach (['subscriptions', 'subscription_price_history', 'attachments', 'budgets'] as $table) {
            $total += (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->q($table)
                . ' WHERE ' . $this->q('household_id') . ' = :household'
                . ' AND ' . $this->q('owner_user_id') . ' = :user',
                ['household' => $this->householdId, 'user' => $userId],
            );
        }

        return $total;
    }

    private function payerOf(int $subscriptionId): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->q('payer_user_id') . ' FROM ' . $this->q('subscriptions')
            . ' WHERE ' . $this->q('id') . ' = :id',
            ['id' => $subscriptionId],
        );

        return $row === null || $row['payer_user_id'] === null ? null : (int) $row['payer_user_id'];
    }

    private function ownerOf(int $subscriptionId): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->q('owner_user_id') . ' FROM ' . $this->q('subscriptions')
            . ' WHERE ' . $this->q('id') . ' = :id',
            ['id' => $subscriptionId],
        );

        return $row === null ? null : (int) $row['owner_user_id'];
    }

    private function roleOf(int $userId): ?Role
    {
        return (new MembershipRepository($this->db))
            ->findForUserAndHousehold($userId, $this->householdId)?->role;
    }

    private function memberNamed(string $displayName): \App\Domain\Entity\User
    {
        foreach ((new UserRepository($this->db))->findAll() as $user) {
            if ($user->displayName === $displayName) {
                return $user;
            }
        }

        self::fail(sprintf('No account named "%s" was created.', $displayName));
    }

    /**
     * Give the editor a real password, a live session row and an API token, so
     * that revocation has all three to take away.
     *
     * @return string The API token, which is the one of the three a test has
     *                to hold on to in order to present it afterwards.
     */
    private function giveEditorASessionAndAToken(): string
    {
        $users = new UserRepository($this->db);
        $users->updatePasswordHash($this->editorId, (new PasswordHasher())->hash('editor-password'));

        $this->db->insert('sessions', [
            'id' => 'editor-session-' . bin2hex(random_bytes(4)),
            'user_id' => $this->editorId,
            'payload' => '',
            'last_activity' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'expires_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ], 'id');

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $editor = $users->findById($this->editorId);
        self::assertNotNull($editor);

        return $container->get(ApiTokenService::class)->issue(
            $editor,
            $this->householdId,
            'Test token',
            TokenScopes::readEverything(),
        );
    }

    private function countSessionsFor(int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->q('sessions') . ' WHERE ' . $this->q('user_id') . ' = :user',
            ['user' => $userId],
        );
    }

    private function countHouseholds(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . $this->q('households'));
    }

    private function hasAudit(AuditAction $action, int $targetUserId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->q('audit_log')
            . ' WHERE ' . $this->q('action') . ' = :action'
            . ' AND ' . $this->q('target_user_id') . ' = :target'
            . ' AND ' . $this->q('actor_user_id') . ' = :actor',
            ['action' => $action->value, 'target' => $targetUserId, 'actor' => $this->ownerId],
        ) !== null;
    }

    /**
     * The token out of the invitation that was actually sent.
     *
     * Read from the message rather than from `auth_tokens`, deliberately: the
     * table holds only a hash, and a test that issued its own token would
     * prove the flow works for a token nobody was ever sent.
     */
    private function inviteLinkToken(): string
    {
        $token = $this->mailer->lastToken();
        self::assertNotNull($token, 'No invitation was sent.');
        self::assertStringContainsString('/accept-invite?token=', $this->mailer->lastBody());

        return $token;
    }

    private function q(string $identifier): string
    {
        return $this->db->platform()->quoteIdentifier($identifier);
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, mixed> $body
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
            $container = $this->app->getContainer();
            self::assertNotNull($container);

            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }

    private function apiRequest(string $method, string $path, string $token): ResponseInterface
    {
        return $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
                ->withHeader('Authorization', 'Bearer ' . $token),
        );
    }
}
