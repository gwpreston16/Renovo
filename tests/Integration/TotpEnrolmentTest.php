<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\MembershipRepository;
use App\Repository\RecoveryCodeRepository;
use App\Repository\TotpRepository;
use App\Repository\UserRepository;
use App\Repository\WebAuthnCredentialRepository;
use App\Security\PasswordHasher;
use App\Security\RequestContext;
use App\Security\RequestContextHolder;
use App\Security\KeyRing;
use App\Security\SecretCipher;
use App\Security\Totp;
use App\Service\AuditLogService;
use App\Service\Auth\RecoveryCodeService;
use App\Service\Auth\TotpService;
use App\Service\Auth\TwoFactorService;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use Psr\Log\NullLogger;

/**
 * Enrolling in TOTP, proving it, using it, and getting back in without it.
 */
final class TotpEnrolmentTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private TotpService $totp;
    private TwoFactorService $twoFactor;
    private RecoveryCodeService $recovery;
    private TotpRepository $enrolments;
    private RecoveryCodeRepository $recoveryCodes;
    private AuditLogRepository $auditLog;
    private UserRepository $users;
    private FrozenClock $clock;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-09-14 12:00:00');
        $this->users = new UserRepository($this->db);
        $this->enrolments = new TotpRepository($this->db);
        $this->recoveryCodes = new RecoveryCodeRepository($this->db);
        $this->auditLog = new AuditLogRepository($this->db);

        $hasher = new PasswordHasher();

        $audit = new AuditLogService(
            $this->auditLog,
            new MembershipRepository($this->db),
            new RequestContextHolder(RequestContext::of('198.51.100.20', 'PHPUnit')),
            $this->clock,
            new NullLogger(),
        );

        $credentials = new WebAuthnCredentialRepository($this->db);
        $this->recovery = new RecoveryCodeService($this->recoveryCodes, $hasher, $audit, $this->clock);

        $this->totp = new TotpService(
            $this->enrolments,
            $this->recovery,
            $credentials,
            new SecretCipher(new KeyRing('test-instance-secret-for-totp')),
            $hasher,
            $audit,
            $this->clock,
            new NullLogger(),
        );

        $this->twoFactor = new TwoFactorService(
            $this->totp,
            $this->recovery,
            $credentials,
            $this->users,
            $hasher,
            $audit,
            new \App\Tests\Support\ArraySession(),
            $this->clock,
        );

        $id = $this->users->create(
            'totp@example.test',
            'TOTP User',
            $hasher->hash(self::PASSWORD),
            false,
            $this->clock->now(),
        );

        $user = $this->users->findById($id);
        self::assertNotNull($user);
        $this->user = $user;
    }

    public function testEnrolmentOnlyCountsOnceTheCodeIsProved(): void
    {
        self::assertFalse($this->totp->isEnabled($this->user->id));

        $enrolment = $this->totp->beginEnrolment($this->user, 'Renovo Test');

        // A started enrolment is not a live second factor: a secret the user's
        // app might never have received must not lock them out.
        self::assertFalse($this->totp->isEnabled($this->user->id));
        self::assertStringContainsString('otpauth://totp/', $enrolment['uri']);

        $codes = $this->totp->confirmEnrolment($this->user, $this->codeFor($enrolment['secret']));

        self::assertTrue($this->totp->isEnabled($this->user->id));
        self::assertCount(RecoveryCodeService::CODE_COUNT, $codes);
        self::assertSame(RecoveryCodeService::CODE_COUNT, $this->twoFactor->unusedRecoveryCodeCount($this->user->id));
        self::assertTrue($this->hasAudit(AuditAction::TotpEnabled));
    }

    public function testTheSecretIsNotStoredInReadableForm(): void
    {
        $enrolment = $this->totp->beginEnrolment($this->user, 'Renovo Test');

        $stored = $this->db->fetchValue(
            'SELECT ' . $this->db->platform()->quoteIdentifier('secret')
            . ' FROM ' . $this->db->platform()->quoteIdentifier('user_totp')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('user_id') . ' = :user',
            ['user' => $this->user->id],
        );

        self::assertIsString($stored);
        self::assertStringNotContainsString($enrolment['secret'], $stored);
    }

    public function testAWrongCodeDoesNotCompleteEnrolment(): void
    {
        $this->totp->beginEnrolment($this->user, 'Renovo Test');

        try {
            $this->totp->confirmEnrolment($this->user, '000000');
            self::fail('A wrong code should not complete enrolment.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('code', $exception->errors());
        }

        self::assertFalse($this->totp->isEnabled($this->user->id));
    }

    public function testACodeCannotBeUsedTwice(): void
    {
        $secret = $this->enrol();
        $code = $this->codeFor($secret);

        self::assertTrue($this->totp->verify($this->user->id, $code));

        // Same code, same thirty-second window, second attempt: refused.
        self::assertFalse($this->totp->verify($this->user->id, $code));

        // The next window works as normal.
        $this->clock->advanceTo($this->clock->now()->modify('+60 seconds'));
        self::assertTrue($this->totp->verify($this->user->id, $this->codeFor($secret)));
    }

    public function testASpentStepCannotBeSpentAgain(): void
    {
        $this->enrol();
        $step = Totp::stepAt($this->clock->now()->getTimestamp()) + 5;

        // Two requests that both read the old last_used_step and then write:
        // the database lets the first through and refuses the second, and a
        // step at or below the one already spent never moves it back.
        self::assertTrue($this->enrolments->recordUsedStep($this->user->id, $step));
        self::assertFalse($this->enrolments->recordUsedStep($this->user->id, $step));
        self::assertFalse($this->enrolments->recordUsedStep($this->user->id, $step - 1));
        self::assertSame($step, $this->enrolments->find($this->user->id)['last_used_step'] ?? null);

        self::assertTrue($this->enrolments->recordUsedStep($this->user->id, $step + 1));
    }

    public function testACodeWhoseStepWasSpentAfterItWasReadIsRefused(): void
    {
        $secret = $this->enrol();
        $code = $this->codeFor($secret);

        // What a concurrent request leaves behind between this one's read of
        // last_used_step and its write: the same step, already spent.
        $step = Totp::stepAt($this->clock->now()->getTimestamp());
        self::assertTrue($this->enrolments->recordUsedStep($this->user->id, $step));

        self::assertFalse($this->totp->verify($this->user->id, $code));
    }

    public function testAnUnreadableSetupSecretReturnsTheSetupFormError(): void
    {
        $otherKey = new SecretCipher(new KeyRing('a-key-that-is-no-longer-configured'));
        $this->enrolments->startEnrolment(
            $this->user->id,
            $otherKey->encrypt(Totp::generateSecret()),
            $this->clock->now(),
        );

        try {
            $this->totp->confirmEnrolment($this->user, '123456');
            self::fail('An unreadable secret must not confirm anything.');
        } catch (ValidationException $exception) {
            self::assertSame('error.totp.setup_unreadable', $exception->errors()['code']->key);
        }

        self::assertFalse($this->totp->isEnabled($this->user->id));
        self::assertSame(0, $this->recoveryCodes->countUnused($this->user->id));
    }

    public function testRecoveryCodesWorkOnceEach(): void
    {
        $this->totp->beginEnrolment($this->user, 'Renovo Test');
        $enrolment = $this->enrolments->find($this->user->id);
        self::assertNotNull($enrolment);

        $codes = $this->totp->confirmEnrolment(
            $this->user,
            $this->codeFor(
                (new SecretCipher(new KeyRing('test-instance-secret-for-totp')))->decrypt($enrolment['secret']),
            ),
        );

        self::assertTrue($this->twoFactor->redeemRecoveryCode($this->user, $codes[0]));
        self::assertFalse($this->twoFactor->redeemRecoveryCode($this->user, $codes[0]));
        self::assertSame(
            RecoveryCodeService::CODE_COUNT - 1,
            $this->twoFactor->unusedRecoveryCodeCount($this->user->id),
        );

        // Written down with the punctuation, typed back without it.
        $asTyped = strtolower(str_replace('-', '', $codes[1]));
        self::assertTrue($this->twoFactor->redeemRecoveryCode($this->user, $asTyped));
        self::assertTrue($this->hasAudit(AuditAction::RecoveryCodeUsed));
    }

    public function testRecoveryCodesAreStoredHashed(): void
    {
        $this->enrol();

        foreach ($this->recoveryCodes->findUnused($this->user->id) as $row) {
            self::assertStringStartsWith('$', $row['code_hash']);
        }
    }

    public function testRegeneratingCodesInvalidatesTheOldOnes(): void
    {
        $this->enrol();
        $original = $this->recoveryCodes->findUnused($this->user->id);

        $fresh = $this->twoFactor->regenerateRecoveryCodes($this->user, self::PASSWORD);

        self::assertCount(RecoveryCodeService::CODE_COUNT, $fresh);
        self::assertNotEquals(
            array_column($original, 'code_hash'),
            array_column($this->recoveryCodes->findUnused($this->user->id), 'code_hash'),
        );
        self::assertTrue($this->hasAudit(AuditAction::RecoveryCodesRegenerated));
    }

    public function testDisablingRequiresThePassword(): void
    {
        $this->enrol();

        try {
            $this->totp->disable($this->user, 'not-the-password');
            self::fail('Disabling should require the current password.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('password', $exception->errors());
        }

        self::assertTrue($this->totp->isEnabled($this->user->id));

        $this->totp->disable($this->user, self::PASSWORD);

        self::assertFalse($this->totp->isEnabled($this->user->id));
        self::assertSame(0, $this->twoFactor->unusedRecoveryCodeCount($this->user->id));
        self::assertTrue($this->hasAudit(AuditAction::TotpDisabled));
    }

    public function testVerifyingFailsForAnAccountWithNoEnrolment(): void
    {
        self::assertFalse($this->totp->verify($this->user->id, '123456'));
    }

    /**
     * @return string The plain base32 secret.
     */
    private function enrol(): string
    {
        $enrolment = $this->totp->beginEnrolment($this->user, 'Renovo Test');
        $this->totp->confirmEnrolment($this->user, $this->codeFor($enrolment['secret']));

        // Enrolment confirms with the current step, which is then spent. Move
        // on so the tests that follow are not fighting the replay guard.
        $this->clock->advanceTo($this->clock->now()->modify('+60 seconds'));

        return $enrolment['secret'];
    }

    private function codeFor(string $secret): string
    {
        return Totp::codeForStep($secret, Totp::stepAt($this->clock->now()->getTimestamp()));
    }

    private function hasAudit(AuditAction $action): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->db->platform()->quoteIdentifier('audit_log')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('action') . ' = :action',
            ['action' => $action->value],
        ) !== null;
    }
}
