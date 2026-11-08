<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Controller\SecurityController;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\TotpRepository;
use App\Repository\UserRepository;
use App\Repository\WebAuthnCredentialRepository;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
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
 * Phase 27: every account setting on one page, for every role.
 *
 * What is asserted here is the page as a whole — that a Viewer can reach and
 * change all of it, that none of it reaches past the signed-in account, that
 * the appearance form saves the fields it was sent and no others, and that
 * the screens it replaced land on the right section. The behaviour of each
 * card has its own test: AccountSelfServiceTest, SessionManagementTest,
 * PaletteTest and PersonalisationTest.
 */
final class ProfilePageTest extends DatabaseTestCase
{
    private const PASSWORD = 'the-current-password';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private UserRepository $users;

    private int $viewerId;
    private int $ownerId;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');

        $this->users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $hash = (new PasswordHasher())->hash(self::PASSWORD);
        $verified = new DateTimeImmutable();

        $this->ownerId = $this->users->create('owner@example.test', 'Olive Owner', $hash, false, $verified);
        $this->viewerId = $this->users->create('viewer@example.test', 'Vic Viewer', $hash, false, $verified);

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->users->updatePreferences($this->ownerId, ['theme' => 'light', 'palette' => 'navy']);
    }

    // ------------------------------------------------------------ every role

    /**
     * A Viewer may change nothing in the household, and everything here: it
     * all acts on their own account.
     */
    public function testAViewerCanChangeEverySettingOnThePage(): void
    {
        $this->signIn($this->viewerId);

        self::assertSame(200, $this->request('GET', '/profile')->getStatusCode());

        $this->assertLandsOn('/profile', $this->request('POST', '/profile/details', [
            'display_name' => 'Vic Renamed',
            'email' => 'viewer@example.test',
        ]));
        $this->assertLandsOn('/profile#appearance', $this->request('POST', '/profile/preferences', [
            'theme' => 'dark',
            'palette' => 'forest',
            'density' => 'compact',
            'week_start' => '0',
            'landing_view' => 'calendar',
        ]));
        $this->assertLandsOn('/profile', $this->request('POST', '/profile/password', [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirm' => 'a-brand-new-password',
            'sign_out_others' => '1',
        ]));

        $viewer = $this->users->findById($this->viewerId);
        self::assertNotNull($viewer);
        self::assertSame('Vic Renamed', $viewer->displayName);
        self::assertSame('dark', $viewer->theme);
        self::assertSame('forest', $viewer->palette);
        self::assertSame('compact', $viewer->density);
        self::assertSame(0, $viewer->weekStart);
        self::assertSame('calendar', $viewer->landingView);
        self::assertTrue((new PasswordHasher())->verify('a-brand-new-password', $viewer->passwordHash));
    }

    /**
     * Every label on the page names a field. The cards carry fixed ids a
     * redirect lands on (`#password` among them), and a field sharing one
     * would hand its label to the card: `for` resolves to the first element
     * with the id, and the field is left with no accessible name.
     */
    public function testEveryLabelOnThePageNamesAUniqueField(): void
    {
        $this->signIn($this->viewerId);

        $html = (string) $this->request('GET', '/profile')->getBody();

        $document = new \DOMDocument();
        // The parser predates HTML5 and complains about its elements; the
        // tree it builds is still the one the ids and labels live in.
        @$document->loadHTML($html);

        $seen = [];
        foreach ((new \DOMXPath($document))->query('//*[@id]') ?: [] as $element) {
            assert($element instanceof \DOMElement);
            $id = $element->getAttribute('id');
            self::assertArrayNotHasKey($id, $seen, "id \"{$id}\" is used twice");
            $seen[$id] = $element;
        }

        $labels = $document->getElementsByTagName('label');
        self::assertGreaterThan(0, $labels->length);
        foreach ($labels as $label) {
            $for = $label->getAttribute('for');
            if ($for === '') {
                continue;
            }
            self::assertArrayHasKey($for, $seen, "label for=\"{$for}\" names nothing");
            self::assertContains(
                $seen[$for]->nodeName,
                ['input', 'select', 'textarea'],
                "label for=\"{$for}\" names a <{$seen[$for]->nodeName}>, not a field",
            );
        }

        self::assertStringContainsString('<label for="new_password">', $html);
    }

    /**
     * No route on the page takes the account it acts on from the request, so
     * naming somebody else's id changes only your own.
     */
    public function testNobodyCanChangeAnotherAccountsSettings(): void
    {
        $this->signIn($this->viewerId);

        $this->request('POST', '/profile/details', [
            'display_name' => 'Hijacked',
            'email' => 'viewer@example.test',
            'user_id' => (string) $this->ownerId,
            'id' => (string) $this->ownerId,
        ]);
        $this->request('POST', '/profile/preferences', [
            'theme' => 'dark',
            'palette' => 'plum',
            'user_id' => (string) $this->ownerId,
        ]);

        $owner = $this->users->findById($this->ownerId);
        self::assertNotNull($owner);
        self::assertSame('Olive Owner', $owner->displayName);
        self::assertSame('light', $owner->theme);
        self::assertSame('navy', $owner->palette);

        self::assertSame('Hijacked', $this->users->findById($this->viewerId)?->displayName);
    }

    /**
     * The security half of the page, for the same Viewer: starting an
     * authenticator, renaming and removing their own passkey, and signing out
     * one of their own other sessions.
     */
    public function testAViewerCanManageTheirOwnSignIn(): void
    {
        $this->signIn($this->viewerId);

        $setup = $this->request('POST', '/profile/two-step/totp', ['password' => self::PASSWORD]);
        self::assertSame(200, $setup->getStatusCode());
        self::assertStringContainsString('action="/profile/two-step/totp/confirm"', (string) $setup->getBody());

        $passkey = $this->passkeyFor($this->viewerId, 'Old name');
        $this->assertLandsOn('/profile#two-step', $this->request(
            'POST',
            '/profile/two-step/passkeys/' . $passkey . '/rename',
            ['name' => 'Laptop'],
        ));
        self::assertSame('Laptop', $this->passkeyName($passkey));

        $this->assertLandsOn(
            '/profile#two-step',
            $this->request(
                'POST',
                '/profile/two-step/passkeys/' . $passkey . '/delete',
                ['password' => self::PASSWORD],
            ),
        );
        self::assertNull($this->passkeyName($passkey));

        $this->db->insert('sessions', [
            'id' => 'viewer-phone-session',
            'user_id' => $this->viewerId,
            'payload' => 'x',
            'last_activity' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'expires_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ], 'id');
        $this->assertLandsOn('/profile#sessions', $this->request('POST', '/profile/sessions/revoke', [
            'handle' => substr('viewer-phone-session', 0, 16),
        ]));
        self::assertNull($this->db->fetchOne(
            'SELECT 1 FROM ' . $this->q('sessions') . ' WHERE ' . $this->q('id') . ' = :id',
            ['id' => 'viewer-phone-session'],
        ));
    }

    /**
     * With two passkeys a screen reader must be able to tell which one a
     * control renames (K220): the summary and the field each carry an
     * accessible name with their own passkey's name and not the other's, as
     * Remove already does.
     */
    public function testEachPasskeysRenameControlAndFieldNameIt(): void
    {
        $this->passkeyFor($this->viewerId, 'Phone');
        $this->passkeyFor($this->viewerId, 'Laptop');
        $this->signIn($this->viewerId);

        $document = new \DOMDocument();
        @$document->loadHTML((string) $this->request('GET', '/profile')->getBody());
        $xpath = new \DOMXPath($document);

        foreach (['Phone' => 'Laptop', 'Laptop' => 'Phone'] as $own => $other) {
            $row = $xpath->query(
                "//li[.//*[contains(@class, 'account-row-name')][normalize-space(.) = '" . $own . "']]",
            )?->item(0);
            self::assertInstanceOf(\DOMElement::class, $row, 'the page lists the ' . $own . ' passkey');

            $summary = $xpath->query(".//summary[starts-with(@aria-label, 'Rename')]", $row)?->item(0);
            $field = $xpath->query(".//input[@name='name']", $row)?->item(0);
            self::assertInstanceOf(\DOMElement::class, $summary);
            self::assertInstanceOf(\DOMElement::class, $field);

            self::assertSame('Rename ' . $own, $summary->getAttribute('aria-label'));
            self::assertSame('New name for ' . $own, $field->getAttribute('aria-label'));
            self::assertStringNotContainsString($other, $summary->getAttribute('aria-label'));
            self::assertStringNotContainsString($other, $field->getAttribute('aria-label'));
        }
    }

    /**
     * The passkey routes are the only ones on the page that take an id from
     * the request, so they are where "only your own" is really decided: an id
     * belonging to another account renames and removes nothing.
     */
    public function testAnotherAccountsPasskeyCannotBeRenamedOrRemoved(): void
    {
        $ownersPasskey = $this->passkeyFor($this->ownerId, 'Owner phone');
        $this->signIn($this->viewerId);

        $this->request('POST', '/profile/two-step/passkeys/' . $ownersPasskey . '/rename', ['name' => 'Mine now']);
        $this->request('POST', '/profile/two-step/passkeys/' . $ownersPasskey . '/delete');

        self::assertSame('Owner phone', $this->passkeyName($ownersPasskey));
    }

    // ----------------------------------------------------------- appearance

    /**
     * Saved on change, the form may arrive with any subset of its fields, and
     * the language control is not drawn at all while one catalogue exists. A
     * field that was not sent is a field left alone.
     */
    public function testAPreferenceThatWasNotSentIsLeftAsItWas(): void
    {
        $this->users->updatePreferences($this->viewerId, [
            'theme' => 'dark',
            'palette' => 'ocean',
            'density' => 'compact',
            'landing_view' => 'calendar',
            'locale' => 'en',
        ]);
        $this->signIn($this->viewerId);

        $this->request('POST', '/profile/preferences', ['week_start' => '0']);

        $viewer = $this->users->findById($this->viewerId);
        self::assertNotNull($viewer);
        self::assertSame(0, $viewer->weekStart);
        self::assertSame('dark', $viewer->theme);
        self::assertSame('ocean', $viewer->palette);
        self::assertSame('compact', $viewer->density);
        self::assertSame('calendar', $viewer->landingView);
        self::assertSame('en', $viewer->locale);
    }

    /**
     * With script, a save is a 204 naming what the page should now wear, so
     * app.js can restyle it in place.
     */
    public function testASaveOnChangeAnswersWithWhatThePageShouldWear(): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request('POST', '/profile/preferences', [
            'theme' => 'dark',
            'palette' => 'forest',
            'density' => 'compact',
        ], htmx: true);

        self::assertSame(204, $response->getStatusCode());

        $trigger = json_decode($response->getHeaderLine('HX-Trigger'), true);
        self::assertIsArray($trigger);
        self::assertSame('dark', $trigger['renovo:preferences']['theme'] ?? null);
        self::assertSame('forest', $trigger['renovo:preferences']['palette'] ?? null);
        self::assertSame('compact', $trigger['renovo:preferences']['density'] ?? null);
        self::assertSame('Your preferences have been saved.', $trigger['renovo:preferences']['message'] ?? null);
    }

    /**
     * A refusal is never a silent 204: the page reloads onto the section with
     * the error on it.
     */
    public function testARefusedSaveOnChangeReloadsTheSectionWithTheError(): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request('POST', '/profile/preferences', ['palette' => 'hotpink'], htmx: true);

        // A refresh rather than HX-Redirect: from /profile, a redirect to
        // /profile#appearance would only scroll, and the error would never load.
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('true', $response->getHeaderLine('HX-Refresh'));
        self::assertStringContainsString(
            'Choose one of the palettes shown.',
            (string) $this->request('GET', '/profile')->getBody(),
        );
    }

    /**
     * Every appearance control, and a Save button for a browser without
     * script — but no language control while only English exists.
     */
    public function testTheAppearanceSectionOffersEveryControl(): void
    {
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        foreach (['theme', 'palette', 'density', 'week_start'] as $name) {
            self::assertStringContainsString('type="radio" name="' . $name . '"', $body, $name);
        }
        self::assertStringContainsString('name="landing_view"', $body);
        self::assertStringContainsString('hx-trigger="change"', $body);
        self::assertMatchesRegularExpression(
            '~<noscript>\s*<div class="form-actions">\s*<button type="submit"~',
            $body,
        );
        self::assertStringNotContainsString('name="locale"', $body);
    }

    // ------------------------------------------------------------ two-step

    public function testTheAuthenticatorSaysSinceWhenAndHowManyCodesAreLeft(): void
    {
        $this->db->insert('user_totp', [
            'user_id' => $this->viewerId,
            'secret' => 'not-read-by-this-page',
            'confirmed_at' => '2026-03-03 09:00:00',
            'last_used_step' => null,
            'created_at' => '2026-03-03 08:59:00',
        ], 'user_id');
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        self::assertStringContainsString('On since 3 Mar 2026 · 0 of 10 recovery codes left', $body);
        self::assertStringContainsString('action="/profile/two-step/totp/disable"', $body);
        self::assertStringContainsString('action="/profile/two-step/recovery-codes"', $body);
    }

    // ---------------------------------------------- the two-step forms (K203)

    /**
     * Start an authenticator enrolment over HTTP and answer with the code its
     * secret makes now, as the app on the member's phone would.
     */
    private function currentAuthenticatorCode(): string
    {
        $secret = $this->session->get('totp_enrolment_secret');
        self::assertIsString($secret, 'the enrolment kept its secret for the confirm step');

        return \App\Security\Totp::codeForStep($secret, \App\Security\Totp::stepAt(time()));
    }

    /**
     * Enrol the Viewer's authenticator through the forms and return the
     * recovery codes the confirm step handed over.
     *
     * @return list<string>
     */
    private function enrolAuthenticator(): array
    {
        $this->signIn($this->viewerId);
        $this->request('POST', '/profile/two-step/totp', ['password' => self::PASSWORD]);
        $this->assertLandsOn('/profile#two-step', $this->request(
            'POST',
            '/profile/two-step/totp/confirm',
            ['code' => $this->currentAuthenticatorCode()],
        ));

        $codes = $this->session->get(SecurityController::RECOVERY_CODES_KEY);
        self::assertIsArray($codes);

        return array_values($codes);
    }

    private function authenticatorIsOn(): bool
    {
        return (new TotpRepository($this->db))->isConfirmed($this->viewerId);
    }

    public function testConfirmingTheAuthenticatorWithItsCodeTurnsItOnAndShowsRecoveryCodes(): void
    {
        $codes = $this->enrolAuthenticator();

        self::assertTrue($this->authenticatorIsOn());
        self::assertCount(10, $codes);
        self::assertNull($this->session->get('totp_enrolment_secret'), 'the pending secret is dropped');

        $page = (string) $this->request('GET', '/profile')->getBody();
        self::assertStringContainsString('Two-factor authentication is on', $page);
        self::assertStringContainsString($codes[0], $page, 'the codes are shown once, on the page');
    }

    public function testConfirmingWithAWrongCodeAnswers422AndTurnsNothingOn(): void
    {
        $this->signIn($this->viewerId);
        $this->request('POST', '/profile/two-step/totp', ['password' => self::PASSWORD]);

        $response = $this->request('POST', '/profile/two-step/totp/confirm', ['code' => '000000']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('That code is not correct', (string) $response->getBody());
        self::assertFalse($this->authenticatorIsOn());
        self::assertNull($this->session->get(SecurityController::RECOVERY_CODES_KEY));
    }

    public function testDisablingTheAuthenticatorNeedsThePassword(): void
    {
        $this->enrolAuthenticator();

        $this->assertLandsOn('/profile#two-step', $this->request(
            'POST',
            '/profile/two-step/totp/disable',
            ['password' => 'not-the-password'],
        ));
        self::assertTrue($this->authenticatorIsOn(), 'a wrong password leaves it on');

        $this->assertLandsOn('/profile#two-step', $this->request(
            'POST',
            '/profile/two-step/totp/disable',
            ['password' => self::PASSWORD],
        ));
        self::assertFalse($this->authenticatorIsOn());
        self::assertStringContainsString(
            'Two-factor authentication is off',
            (string) $this->request('GET', '/profile')->getBody(),
        );
    }

    public function testNewRecoveryCodesNeedThePasswordAndRetireTheOldOnes(): void
    {
        $old = $this->enrolAuthenticator();
        $this->session->remove(SecurityController::RECOVERY_CODES_KEY);

        $this->request('POST', '/profile/two-step/recovery-codes', ['password' => 'not-the-password']);
        self::assertNull(
            $this->session->get(SecurityController::RECOVERY_CODES_KEY),
            'a wrong password issues nothing',
        );

        $this->assertLandsOn('/profile#two-step', $this->request(
            'POST',
            '/profile/two-step/recovery-codes',
            ['password' => self::PASSWORD],
        ));
        $new = $this->session->get(SecurityController::RECOVERY_CODES_KEY);
        self::assertIsArray($new);
        self::assertCount(10, $new);
        self::assertNotSame($old, array_values($new));

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $recovery = $container->get(\App\Service\Auth\RecoveryCodeService::class);
        $user = $this->users->findById($this->viewerId);
        self::assertNotNull($user);

        self::assertFalse($recovery->redeem($user, $old[0]), 'an old code no longer works');
        self::assertTrue($recovery->redeem($user, (string) array_values($new)[0]), 'a new one does');
    }

    /**
     * Recovery codes are shown on the page every issuing action lands on, and
     * only on the first render of it.
     */
    public function testFreshRecoveryCodesAreShownOnceOnTheProfile(): void
    {
        $this->signIn($this->viewerId);
        $this->session->set(SecurityController::RECOVERY_CODES_KEY, ['abcd-efgh-ijkl']);

        self::assertStringContainsString('abcd-efgh-ijkl', (string) $this->request('GET', '/profile')->getBody());
        self::assertStringNotContainsString('abcd-efgh-ijkl', (string) $this->request('GET', '/profile')->getBody());
    }

    public function testAddingAPasskeyIsHiddenUntilScriptOffersIt(): void
    {
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        self::assertStringContainsString('<div class="stack" id="passkey-add" hidden>', $body);
        self::assertStringContainsString('data-options-url="/profile/two-step/passkeys/options"', $body);
    }

    /**
     * The security routes moved under /profile, and the middleware that
     * holds a member on a temporary password names its /profile paths one at
     * a time — so enrolling a factor stays shut until they have chosen one.
     */
    public function testAMemberOnATemporaryPasswordCannotEnrolAFactor(): void
    {
        $this->users->setMustChangePassword($this->viewerId, true);
        $this->signIn($this->viewerId);

        $response = $this->request('POST', '/profile/two-step/totp');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile', $response->getHeaderLine('Location'));
    }

    // ---------------------------------------------------------- old routes

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function movedScreens(): array
    {
        return [
            'your account' => ['/profile/account', '/profile#details'],
            'account security' => ['/settings/security', '/profile#two-step'],
        ];
    }

    /**
     * @dataProvider movedScreens
     */
    public function testEveryOldRouteRedirectsToItsSection(string $old, string $section): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request('GET', $old);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($section, $response->getHeaderLine('Location'));

        // And the fragment names a section that is on the page.
        $id = substr($section, (int) strpos($section, '#') + 1);
        self::assertStringContainsString(
            'id="' . $id . '"',
            (string) $this->request('GET', '/profile')->getBody(),
        );
    }

    public function testThePageEndsWithAWayOut(): void
    {
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        self::assertStringContainsString('<form method="post" action="/logout" class="profile-signout">', $body);
    }

    // ----------------------------------------------------------------- tools

    private function assertLandsOn(string $location, ResponseInterface $response): void
    {
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($location, $response->getHeaderLine('Location'));
    }

    private function passkeyFor(int $userId, string $name): int
    {
        return (new WebAuthnCredentialRepository($this->db))->create(
            $userId,
            'credential-' . bin2hex(random_bytes(8)),
            '{}',
            $name,
            null,
            ['internal'],
            0,
            true,
            new DateTimeImmutable(),
        );
    }

    private function passkeyName(int $id): ?string
    {
        $name = $this->db->fetchValue(
            'SELECT ' . $this->q('name') . ' FROM ' . $this->q('webauthn_credentials')
            . ' WHERE ' . $this->q('id') . ' = :id',
            ['id' => $id],
        );

        return $name === null ? null : (string) $name;
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
     * K174: the challenge a registration completes against is only handed out
     * for the right password, and none is kept for a wrong one.
     */
    public function testAPasskeyChallengeNeedsThePassword(): void
    {
        $this->signIn($this->viewerId);

        foreach ([[], ['password' => 'not-the-password']] as $payload) {
            $response = $this->passkeyOptions($payload);

            self::assertSame(422, $response->getStatusCode());
            self::assertNull($this->session->get('passkey_registration_options'));
        }

        $response = $this->passkeyOptions(['password' => self::PASSWORD]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->session->get('passkey_registration_options'));
    }

    /**
     * K210: without the password, setting up an authenticator app shows no QR
     * code and starts nothing.
     */
    public function testAnAuthenticatorAppNeedsThePassword(): void
    {
        $this->signIn($this->viewerId);

        foreach ([[], ['password' => 'not-the-password']] as $body) {
            $response = $this->request('POST', '/profile/two-step/totp', $body);

            $this->assertLandsOn('/profile#two-step', $response);
            self::assertStringNotContainsString('totp/confirm', (string) $response->getBody());
        }

        self::assertNull((new TotpRepository($this->db))->find($this->viewerId));
    }

    /**
     * K212: the page names the field the script reads the password from, and
     * that field is on the page.
     */
    public function testTheRegisterButtonNamesThePasswordField(): void
    {
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/profile')->getBody();

        self::assertStringContainsString('data-password-input="passkey-password"', $body);
        self::assertMatchesRegularExpression('/<input[^>]*id="passkey-password"/', $body);
    }

    public function testAWrongPasswordLeavesThePasskeyWhereItIs(): void
    {
        $this->signIn($this->viewerId);
        $passkey = $this->passkeyFor($this->viewerId, 'Laptop');

        $this->request(
            'POST',
            '/profile/two-step/passkeys/' . $passkey . '/delete',
            ['password' => 'not-the-password'],
        );

        self::assertSame('Laptop', $this->passkeyName($passkey));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function passkeyOptions(array $payload): ResponseInterface
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode($payload, JSON_THROW_ON_ERROR));
        rewind($stream);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest(
                    'POST',
                    'http://localhost/profile/two-step/passkeys/options',
                    ['REMOTE_ADDR' => '127.0.0.1'],
                )
                ->withBody(new \Slim\Psr7\Stream($stream))
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token()),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = [], bool $htmx = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $container = $this->app->getContainer();
            self::assertNotNull($container);

            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        }

        if ($htmx) {
            $request = $request->withHeader('HX-Request', 'true');
        }

        return $this->app->handle($request);
    }
}
