<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Security\Scope;
use App\Security\ScopeFactory;
use App\Service\LogoStorage;
use App\Service\PaymentMethodService;
use App\Repository\PaymentMethodRepository;
use App\Repository\AuditLogRepository;
use App\Repository\AuthAttemptRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SessionRepository;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\RequestContext;
use App\Security\RequestContextHolder;
use App\Service\AuditLogService;
use App\Service\Auth\SessionDirectoryService;
use App\Service\AuthService;
use App\Service\InstanceSettingsService;
use App\Repository\InstanceSettingsRepository;
use App\Service\MailerService;
use App\Service\PasswordResetService;
use App\Service\RateLimiter;
use App\Service\ValidationError;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use App\I18n\CatalogLoader;
use App\I18n\Locales;
use App\Tests\Support\ArraySession;
use App\Tests\Support\TestTranslator;
use App\Tests\Support\RecordingMailer;
use Psr\Log\NullLogger;
use App\Tests\Support\TestKeys;

/**
 * Sign-up through to sign-in, and the throttling that protects both.
 */
final class AuthFlowTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private AuthService $auth;
    private PasswordResetService $resets;
    private UserRepository $users;
    private RecordingMailer $mailer;
    private FrozenClock $clock;
    private AuthAttemptRepository $attempts;
    private AuditLogRepository $auditLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-09-13 10:00:00');
        $this->mailer = new RecordingMailer();
        $this->users = new UserRepository($this->db);
        $this->attempts = new AuthAttemptRepository($this->db, $this->clock);

        $tokens = new TokenRepository($this->db, $this->clock);
        $hasher = new PasswordHasher();
        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        $mailService = new MailerService($this->mailer, new NullLogger(), 'noreply@example.test', 'Test');

        $limiter = new RateLimiter($this->attempts, $this->clock, 3, 10, 900, 900);

        $memberships = new MembershipRepository($this->db);
        $this->auditLog = new AuditLogRepository($this->db);
        $audit = new AuditLogService(
            $this->auditLog,
            $memberships,
            new RequestContextHolder(RequestContext::of('198.51.100.9', 'PHPUnit')),
            $this->clock,
            new NullLogger(),
        );

        $locales = new Locales(new CatalogLoader(dirname(__DIR__, 2) . '/translations'), 'en_GB');

        $this->auth = new AuthService(
            $this->users,
            new HouseholdRepository($this->db),
            $memberships,
            $tokens,
            $hasher,
            $limiter,
            $mailService,
            $settings,
            TestTranslator::create(),
            $locales,
            $audit,
            new PaymentMethodService(
                new PaymentMethodRepository($this->db),
                new LogoStorage(sys_get_temp_dir() . '/renovo-test-logos', 1024 * 1024),
                TestTranslator::create(),
                $locales,
                new ScopeFactory($memberships, $settings),
            ),
            $this->clock,
            'https://renovo.test',
        );

        $this->resets = new PasswordResetService(
            $this->users,
            $tokens,
            $this->attempts,
            $hasher,
            $limiter,
            $mailService,
            $this->auth,
            $settings,
            TestTranslator::create(),
            $locales,
            $audit,
            new SessionDirectoryService(new SessionRepository($this->db), $audit, $this->clock),
            new ArraySession(),
            $this->clock,
            'https://renovo.test',
        );
    }

    /**
     * A new instance is invitation-only until its administrator says
     * otherwise: the setup wizard makes the first account, so nobody needs the
     * sign-up form to get in, and a stranger should not find it open.
     */
    public function testRegistrationIsClosedUntilAnAdministratorOpensIt(): void
    {
        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        self::assertFalse($settings->registrationAllowed());

        $settings->setRegistrationAllowed(true);
        self::assertTrue($settings->registrationAllowed());
    }

    public function testSignUpVerifyAndSignIn(): void
    {
        $user = $this->auth->register('New@Example.test', 'New User', self::PASSWORD, self::PASSWORD);

        // The address is normalised on the way in, so the unique index is an
        // effective case-insensitive constraint.
        self::assertSame('new@example.test', $user->email);
        self::assertFalse($user->isVerified());

        // Signing in before confirming the address is refused.
        try {
            $this->auth->attemptLogin('new@example.test', self::PASSWORD, '10.0.0.1');
            self::fail('An unverified account must not be able to sign in.');
        } catch (ValidationException $exception) {
            self::assertSame(['error.auth.unverified'], self::keysOf($exception));
        }

        $token = $this->mailer->lastToken();
        self::assertNotNull($token, 'A verification email should carry a token.');

        self::assertTrue($this->auth->verifyEmail($token));

        $signedIn = $this->auth->attemptLogin('NEW@example.test', self::PASSWORD, '10.0.0.1');
        self::assertSame($user->id, $signedIn->id);

        // The token is single use.
        self::assertFalse($this->auth->verifyEmail($token));
    }

    public function testSignUpCreatesAHouseholdOwnedByTheNewUser(): void
    {
        $user = $this->auth->register('owner@example.test', 'Owner', self::PASSWORD, self::PASSWORD);

        $memberships = (new MembershipRepository($this->db))->findAllForUser($user->id);

        self::assertCount(1, $memberships);
        self::assertSame('owner_admin', $memberships[0]->role->value);

        // And the household starts with the default payment methods, so there
        // is something to choose from on the first subscription.
        $methods = (new PaymentMethodRepository($this->db))->findAll(Scope::forMember(
            $user->id,
            false,
            $memberships[0]->householdId,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        ));
        self::assertCount(10, $methods);
        self::assertContains('Direct Debit', array_map(static fn ($m): string => $m->name, $methods));
    }

    public function testDuplicateEmailIsRejectedRegardlessOfCase(): void
    {
        $this->auth->register('dup@example.test', 'First', self::PASSWORD, self::PASSWORD);

        $this->expectException(ValidationException::class);

        $this->auth->register('DUP@example.test', 'Second', self::PASSWORD, self::PASSWORD);
    }

    public function testWrongPasswordIsThrottledPerAccount(): void
    {
        $this->verifiedUser('locked@example.test');

        // The limiter is configured with three attempts per account here.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $this->auth->attemptLogin('locked@example.test', 'wrong-password', '10.0.0.' . $attempt);
            } catch (ValidationException) {
                // expected
            }
        }

        // A different address does not help: the account itself is throttled.
        try {
            $this->auth->attemptLogin('locked@example.test', self::PASSWORD, '10.0.0.99');
            self::fail('The account should be locked out.');
        } catch (ValidationException $exception) {
            self::assertSame(['error.auth.throttled'], self::keysOf($exception));
        }
    }

    public function testThrottlingIsAlsoAppliedPerIpAcrossAccounts(): void
    {
        $limiter = new RateLimiter($this->attempts, $this->clock, 100, 3, 900, 900);

        for ($i = 0; $i < 3; $i++) {
            $limiter->recordFailure(AuthAttemptRepository::KIND_LOGIN, 'user' . $i . '@example.test', '203.0.113.7');
        }

        // Password spraying: many accounts, one source address.
        self::assertTrue($limiter->isBlocked(AuthAttemptRepository::KIND_LOGIN, 'fresh@example.test', '203.0.113.7'));
        self::assertFalse($limiter->isBlocked(AuthAttemptRepository::KIND_LOGIN, 'fresh@example.test', '203.0.113.8'));
    }

    public function testLockoutExpiresWhenTheWindowPasses(): void
    {
        $this->verifiedUser('waiting@example.test');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $this->auth->attemptLogin('waiting@example.test', 'wrong-password', '10.0.0.5');
            } catch (ValidationException) {
                // expected
            }
        }

        $this->clock->advanceTo($this->clock->now()->modify('+16 minutes'));

        $user = $this->auth->attemptLogin('waiting@example.test', self::PASSWORD, '10.0.0.5');
        self::assertSame('waiting@example.test', $user->email);
    }

    public function testResetRequestsAreThrottledPerAccount(): void
    {
        $this->verifiedUser('spam@example.test');

        // The limiter here allows three attempts per account. Every reset
        // request counts, successful or not — counting only the misses would
        // let the throttle itself reveal which addresses have accounts.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->resets->request('spam@example.test', '198.51.100.' . $attempt);
        }

        try {
            $this->resets->request('spam@example.test', '198.51.100.9');
            self::fail('A fourth reset request should be refused.');
        } catch (ValidationException $exception) {
            self::assertSame(['error.reset.throttled'], self::keysOf($exception));
        }
    }

    public function testResetRequestsAreThrottledPerIpAcrossAccounts(): void
    {
        $this->verifiedUser('one@example.test');
        $this->verifiedUser('two@example.test');

        $limiter = new RateLimiter($this->attempts, $this->clock, 100, 2, 900, 900);
        $resets = $this->resetServiceUsing($limiter);

        $resets->request('one@example.test', '198.51.100.50');
        $resets->request('two@example.test', '198.51.100.50');

        try {
            $resets->request('one@example.test', '198.51.100.50');
            self::fail('The address should be throttled regardless of which account is named.');
        } catch (ValidationException $exception) {
            self::assertSame(['error.reset.throttled'], self::keysOf($exception));
        }

        // A different address is unaffected.
        $resets->request('one@example.test', '198.51.100.51');
    }

    public function testASuccessfulResetClearsTheAccountsLockout(): void
    {
        $this->verifiedUser('locked-out@example.test');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $this->auth->attemptLogin('locked-out@example.test', 'wrong', '192.0.2.1');
            } catch (ValidationException) {
                // expected
            }
        }

        // Proving control of the mailbox must get the user back in, rather
        // than leaving them locked out until the window expires.
        $this->mailer->reset();
        $limiter = new RateLimiter($this->attempts, $this->clock, 100, 100, 900, 900);
        $resets = $this->resetServiceUsing($limiter);
        $resets->request('locked-out@example.test', '192.0.2.1');

        $token = $this->mailer->lastToken();
        self::assertNotNull($token);
        $resets->reset($token, 'a-fresh-long-password', 'a-fresh-long-password');

        $user = $this->auth->attemptLogin('locked-out@example.test', 'a-fresh-long-password', '192.0.2.1');
        self::assertSame('locked-out@example.test', $user->email);
    }

    public function testPasswordResetRoundTrip(): void
    {
        $this->verifiedUser('reset@example.test');
        $this->mailer->reset();

        $this->resets->request('reset@example.test', '10.0.0.3');

        // Paragraphs, not the two characters "\n": the catalogue once held
        // these bodies in single quotes, which PHP does not unescape, and
        // every mail arrived as one line with the escapes printed in it.
        $body = $this->mailer->lastBody();
        self::assertStringNotContainsString('\n', $body);
        self::assertMatchesRegularExpression("/Hello [^\r\n]+,\r?\n\r?\nSomeone asked/", $body);

        $token = $this->mailer->lastToken();
        self::assertNotNull($token);
        self::assertTrue($this->resets->isTokenValid($token));

        $this->resets->reset($token, 'a-brand-new-password', 'a-brand-new-password');

        // The old password no longer works and the new one does.
        try {
            $this->auth->attemptLogin('reset@example.test', self::PASSWORD, '10.0.0.3');
            self::fail('The old password must stop working.');
        } catch (ValidationException) {
            // expected
        }

        $user = $this->auth->attemptLogin('reset@example.test', 'a-brand-new-password', '10.0.0.3');
        self::assertSame('reset@example.test', $user->email);

        // Reset links are single use.
        self::assertFalse($this->resets->isTokenValid($token));
    }

    public function testResetRequestForAnUnknownAddressRevealsNothingAndSendsNothing(): void
    {
        $this->mailer->reset();

        $this->resets->request('nobody@example.test', '10.0.0.4');

        self::assertSame([], $this->mailer->messages);
    }

    public function testResetTokenExpires(): void
    {
        $this->verifiedUser('expiry@example.test');
        $this->mailer->reset();

        $this->resets->request('expiry@example.test', '10.0.0.6');
        $token = $this->mailer->lastToken();
        self::assertNotNull($token);

        $this->clock->advanceTo($this->clock->now()->modify('+2 hours'));

        self::assertFalse($this->resets->isTokenValid($token));
    }

    /**
     * A reset service sharing this test's repositories but a different limit.
     */
    private function resetServiceUsing(RateLimiter $limiter): PasswordResetService
    {
        return new PasswordResetService(
            $this->users,
            new TokenRepository($this->db, $this->clock),
            $this->attempts,
            new PasswordHasher(),
            $limiter,
            new MailerService($this->mailer, new NullLogger(), 'noreply@example.test', 'Test'),
            $this->auth,
            new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring()),
            TestTranslator::create(),
            new Locales(new CatalogLoader(dirname(__DIR__, 2) . '/translations'), 'en_GB'),
            $this->audit(),
            new SessionDirectoryService(new SessionRepository($this->db), $this->audit(), $this->clock),
            new ArraySession(),
            $this->clock,
            'https://renovo.test',
        );
    }

    private function audit(): AuditLogService
    {
        return new AuditLogService(
            $this->auditLog,
            new MembershipRepository($this->db),
            new RequestContextHolder(RequestContext::of('198.51.100.9', 'PHPUnit')),
            $this->clock,
            new NullLogger(),
        );
    }

    private function verifiedUser(string $email): void
    {
        $user = $this->auth->register($email, 'Test User', self::PASSWORD, self::PASSWORD);
        $this->users->markEmailVerified($user->id, $this->clock->now());
    }

    /**
     * The translation keys a validation failure carried.
     *
     * Asserting on keys rather than on English: the sentence is a property of
     * the catalogue and may be reworded, while the key is the thing the code
     * actually decided.
     *
     * @return list<string>
     */
    private static function keysOf(ValidationException $exception): array
    {
        return array_values(array_map(
            static fn (ValidationError $error): string => $error->key,
            $exception->errors(),
        ));
    }
}
