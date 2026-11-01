<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
use App\Security\SessionInterface;
use App\Service\AuthService;
use App\Service\InstanceSettingsService;
use App\Service\PasswordResetService;
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
 * The signed-out screens (Phase 29): what they say, and what they must not.
 *
 * A restyle over flows that already worked, so most of what is held here is
 * the restyle's promises rather than the flows: sign-in names no household,
 * failures and "check your inbox" read the same whoever is asking, the
 * lifetimes and the password minimum on screen are the ones the server
 * applies, and "Keep me signed in" decides the session's lifetime on every
 * route that signs a browser in.
 */
final class SignedOutScreensTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private ContainerInterface $container;
    private RecordingMailer $mailer;
    private int $userId;

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
        $this->container = $container;

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->setDemoMode(false);
        $settings->setRegistrationAllowed(false);

        $users = new UserRepository($this->db);
        $this->userId = $users->create(
            'sarah@example.test',
            'Sarah',
            (new PasswordHasher())->hash(self::PASSWORD),
            false,
            new DateTimeImmutable(),
        );

        $householdId = (new HouseholdRepository($this->db))->create('Jenkins household', $this->userId);
        (new MembershipRepository($this->db))->create($householdId, $this->userId, Role::OwnerAdmin);
    }

    // ------------------------------------------------------------------
    // Sign in
    // ------------------------------------------------------------------

    public function testSignInNamesNoHousehold(): void
    {
        $html = $this->body('GET', '/login');

        self::assertStringContainsString('Welcome back.', $html);
        self::assertStringNotContainsString('Jenkins', $html);
    }

    public function testAnUnknownAddressAndAWrongPasswordFailIdentically(): void
    {
        $unknown = $this->request('POST', '/login', ['email' => 'nobody@example.test', 'password' => 'whatever-it-is']);
        $wrong = $this->request('POST', '/login', ['email' => 'sarah@example.test', 'password' => 'whatever-it-is']);

        self::assertSame($unknown->getStatusCode(), $wrong->getStatusCode());
        self::assertSame($this->alert((string) $unknown->getBody()), $this->alert((string) $wrong->getBody()));
        self::assertStringContainsString('don’t match an account', $this->alert((string) $wrong->getBody()));
    }

    public function testTheLockOutUsesTheSameAlertWithItsWait(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->request('POST', '/login', ['email' => 'sarah@example.test', 'password' => 'wrong-password-' . $i]);
        }

        $locked = $this->request('POST', '/login', ['email' => 'sarah@example.test', 'password' => self::PASSWORD]);

        self::assertMatchesRegularExpression('~Try again in \d+ minutes?~', $this->alert((string) $locked->getBody()));
    }

    public function testTheRegistrationLinkAppearsOnlyWhenRegistrationIsOpen(): void
    {
        self::assertStringNotContainsString('href="/register"', $this->body('GET', '/login'));

        $this->container->get(InstanceSettingsService::class)->setRegistrationAllowed(true);

        self::assertStringContainsString('href="/register"', $this->body('GET', '/login'));
    }

    public function testThePasswordToggleAndThePasskeyAreNotOfferedToAPageWithoutScript(): void
    {
        $html = $this->body('GET', '/login');

        // Both are in the page, both hidden until the script shows them.
        self::assertMatchesRegularExpression('~<button type="button" class="password-toggle"[^>]*\bhidden>~', $html);
        self::assertMatchesRegularExpression('~<div class="auth-passkey" id="passkey-block" hidden>~', $html);
        self::assertStringContainsString('type="password" id="password" name="password"', $html);
    }

    // ------------------------------------------------------------------
    // Keep me signed in
    // ------------------------------------------------------------------

    public function testASignInKeepsTheSessionUnlessAskedNotTo(): void
    {
        $this->request('POST', '/login', ['email' => 'sarah@example.test', 'password' => self::PASSWORD]);

        self::assertSame($this->userId, $this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
        self::assertTrue($this->session->isPersistent());
    }

    public function testUntickingKeepMeSignedInEndsTheSessionWithTheBrowser(): void
    {
        $this->request('POST', '/login', [
            'email' => 'sarah@example.test',
            'password' => self::PASSWORD,
            'remember' => '0',
        ]);

        self::assertSame($this->userId, $this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
        self::assertFalse($this->session->isPersistent());
    }

    public function testTheChoiceIsCarriedThroughTheSecondFactor(): void
    {
        $twoFactor = $this->container->get(\App\Service\Auth\TwoFactorService::class);
        $user = (new UserRepository($this->db))->findById($this->userId);
        self::assertNotNull($user);

        $twoFactor->beginChallenge($user, '/', false);
        self::assertFalse($twoFactor->pendingRemember());

        $twoFactor->beginChallenge($user, '/', true);
        self::assertTrue($twoFactor->pendingRemember());
    }

    // ------------------------------------------------------------------
    // The second factor
    // ------------------------------------------------------------------

    public function testTheTwoStepPageIsOneCodeFieldAndARecoveryForm(): void
    {
        $user = (new UserRepository($this->db))->findById($this->userId);
        self::assertNotNull($user);

        $totp = $this->container->get(\App\Service\Auth\TotpService::class);
        $enrolment = $totp->beginEnrolment($user, 'Renovo Test');
        $totp->confirmEnrolment($user, $this->code($enrolment['secret']));
        $user = (new UserRepository($this->db))->findById($this->userId);
        self::assertNotNull($user);
        $twoFactor = $this->container->get(\App\Service\Auth\TwoFactorService::class);
        $twoFactor->regenerateRecoveryCodes($user, self::PASSWORD, '198.51.100.7');

        $this->container->get(\App\Service\Auth\TwoFactorService::class)->beginChallenge($user, '/');
        $html = $this->body('GET', '/login/two-factor');

        $totpForm = $this->between(
            $html,
            '<form method="post" action="/login/two-factor" class="auth-form">',
            '</form>',
        );
        self::assertSame(1, substr_count($totpForm, '<input type="text"'), 'The six boxes are one field.');
        self::assertStringContainsString('autocomplete="one-time-code"', $totpForm);
        self::assertStringContainsString('inputmode="numeric"', $totpForm);
        self::assertStringContainsString('maxlength="6"', $totpForm);

        self::assertStringContainsString('name="mode" value="recovery"', $html);
        self::assertStringContainsString('for sarah@example.test', $html);
    }

    // ------------------------------------------------------------------
    // Forgotten password
    // ------------------------------------------------------------------

    public function testTheLifetimeOnTheForgotAndSentPagesIsTheTokens(): void
    {
        $minutes = $this->container->get(PasswordResetService::class)->tokenLifetimeMinutes();

        self::assertStringContainsString('works for ' . $minutes . ' minutes', $this->body('GET', '/forgot-password'));

        $sent = $this->body('POST', '/forgot-password', ['email' => 'sarah@example.test']);
        self::assertStringContainsString('expires in ' . $minutes . ' minutes', $sent);
    }

    public function testCheckYourInboxIsIdenticalForAnAccountAndForNone(): void
    {
        $known = $this->body('POST', '/forgot-password', ['email' => 'sarah@example.test']);
        $unknown = $this->body('POST', '/forgot-password', ['email' => 'nobody@example.test']);

        self::assertSame(
            $this->card(str_replace('sarah@example.test', '{email}', $known)),
            $this->card(str_replace('nobody@example.test', '{email}', $unknown)),
        );
    }

    public function testAResetEndsOnItsOwnPageAndASpentLinkOffersANewOne(): void
    {
        $this->request('POST', '/forgot-password', ['email' => 'sarah@example.test']);
        $token = $this->mailer->lastTokenFromMessage(0);
        self::assertNotNull($token);

        self::assertStringContainsString('data-password-meter', $this->body('GET', '/reset-password?token=' . $token));

        $done = $this->request('POST', '/reset-password', [
            'token' => $token,
            'password' => 'a brand new password',
            'password_confirm' => 'a brand new password',
        ]);
        self::assertSame(200, $done->getStatusCode());
        self::assertStringContainsString('Password saved', (string) $done->getBody());

        $spent = $this->request('GET', '/reset-password?token=' . $token);
        self::assertSame(410, $spent->getStatusCode());
        self::assertStringContainsString('href="/forgot-password"', (string) $spent->getBody());
    }

    // ------------------------------------------------------------------
    // The password meter
    // ------------------------------------------------------------------

    public function testTheMetersMinimumIsTheValidators(): void
    {
        $this->container->get(InstanceSettingsService::class)->setRegistrationAllowed(true);
        $html = $this->body('GET', '/register');

        self::assertStringContainsString('data-min="' . AuthService::MIN_PASSWORD_LENGTH . '"', $html);
        self::assertStringContainsString('At least ' . AuthService::MIN_PASSWORD_LENGTH . ' characters', $html);

        // And the validator agrees with the number on the page.
        $auth = $this->container->get(AuthService::class);
        $atMinimum = str_repeat('a', AuthService::MIN_PASSWORD_LENGTH);
        self::assertArrayNotHasKey('password', $auth->validatePassword($atMinimum, $atMinimum));
        $below = substr($atMinimum, 1);
        self::assertArrayHasKey('password', $auth->validatePassword($below, $below));
    }

    // ------------------------------------------------------------------
    // Confirming an email address
    // ------------------------------------------------------------------

    public function testSendAgainAnswersTheSameWhateverTheAddress(): void
    {
        $known = $this->body('POST', '/verify-email/resend', ['email' => 'sarah@example.test']);
        $unknown = $this->body('POST', '/verify-email/resend', ['email' => 'nobody@example.test']);

        self::assertSame(
            $this->card(str_replace('sarah@example.test', '{email}', $known)),
            $this->card(str_replace('nobody@example.test', '{email}', $unknown)),
        );
    }

    public function testSendAgainMailsAnAddressThatIsWaiting(): void
    {
        (new UserRepository($this->db))->create('waiting@example.test', 'Waiting', 'hash');

        $this->request('POST', '/verify-email/resend', ['email' => 'waiting@example.test']);

        self::assertNotNull($this->mailer->lastTokenFromMessage(0));
    }

    public function testASpentConfirmationLinkSaysTheAddressIsConfirmed(): void
    {
        $waiting = (new UserRepository($this->db))->create('waiting@example.test', 'Waiting', 'hash');
        $token = (new TokenRepository($this->db, new \App\Support\SystemClock(new \DateTimeZone('UTC'))))->issue(
            $waiting,
            TokenRepository::PURPOSE_VERIFY_EMAIL,
            new DateTimeImmutable('+1 day'),
        );

        $first = $this->request('GET', '/verify-email?token=' . $token);
        self::assertSame(200, $first->getStatusCode());
        self::assertStringContainsString('Email confirmed', (string) $first->getBody());

        $second = $this->request('GET', '/verify-email?token=' . $token);
        self::assertSame(410, $second->getStatusCode());
        self::assertStringContainsString('Already confirmed', (string) $second->getBody());

        $unknown = $this->request('GET', '/verify-email?token=not-a-token');
        self::assertStringContainsString('action="/verify-email/resend"', (string) $unknown->getBody());
    }

    // ------------------------------------------------------------------
    // Error pages
    // ------------------------------------------------------------------

    public function testAnErrorPageOffersAStrangerTheSignInForm(): void
    {
        $response = $this->request('GET', '/no-such-page');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString(
            '<a class="button button-primary auth-submit" href="/login">',
            (string) $response->getBody(),
        );
    }

    public function testAnErrorPageOffersASignedInReaderTheirDashboard(): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->userId);

        $html = (string) $this->request('GET', '/no-such-page')->getBody();

        self::assertStringContainsString('<a class="button button-primary auth-submit" href="/">', $html);
        self::assertStringNotContainsString('data-theme-switch', $html);
    }

    public function testAnExpiredFormSaysTheSessionExpired(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/login', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody(['email' => 'sarah@example.test', 'password' => self::PASSWORD]);

        $html = (string) $this->app->handle($request)->getBody();

        self::assertStringContainsString('Session expired', $html);
        self::assertStringContainsString('href="/login"', $html);
    }

    // ------------------------------------------------------------------
    // The demo banner and the brand panel
    // ------------------------------------------------------------------

    public function testADemonstrationSaysSoAboveTheCard(): void
    {
        self::assertStringNotContainsString('read-only demonstration', $this->body('GET', '/login'));

        $this->container->get(InstanceSettingsService::class)->setDemoMode(true);

        self::assertStringContainsString('read-only demonstration', $this->body('GET', '/login'));
    }

    public function testTheBrandPanelMakesOnlyTheCorrectedClaims(): void
    {
        $html = $this->body('GET', '/login');

        self::assertStringContainsString('chat apps or push notifications', $html);
        self::assertStringContainsString('Your data stays on your own server.', $html);
        self::assertStringNotContainsString('Nothing leaves it', $html);
        self::assertStringNotContainsString('Gotify', $html);
    }

    /**
     * @param array<string, string> $body
     */
    private function body(string $method, string $path, array $body = []): string
    {
        return (string) $this->request($method, $path, $body)->getBody();
    }

    /**
     * @param array<string, string> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $request = $request
                ->withParsedBody($body)
                ->withHeader(
                    CsrfTokenManager::HEADER_NAME,
                    $this->container->get(CsrfTokenManager::class)->token(),
                );
        }

        return $this->app->handle($request);
    }

    private function alert(string $html): string
    {
        return $this->between($html, '<div class="auth-alert" role="alert">', '</div>');
    }

    /** The card alone, without the CSRF fields that differ between two renders. */
    private function card(string $html): string
    {
        $card = $this->between($html, '<section class="auth-card"', '</section>');

        return (string) preg_replace('~name="_csrf" value="[^"]*"~', '', $card);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        self::assertNotFalse($from, 'Could not find ' . $start);

        $to = strpos($html, $end, $from);
        self::assertNotFalse($to, 'Could not find ' . $end);

        return substr($html, $from, $to - $from);
    }

    private function code(string $secret): string
    {
        return \App\Security\Totp::codeForStep($secret, \App\Security\Totp::stepAt(time()) - 1);
    }
}
