<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\AuditLogRepository;
use App\Repository\MembershipRepository;
use App\Repository\RecoveryCodeRepository;
use App\Repository\TotpRepository;
use App\Repository\UserRepository;
use App\Repository\WebAuthnCredentialRepository;
use App\Security\RequestContext;
use App\Security\RequestContextHolder;
use App\Security\PasswordHasher;
use App\Security\KeyRing;
use App\Security\SecretCipher;
use App\Service\AuditLogService;
use App\Service\Auth\RecoveryCodeService;
use App\Service\Auth\TotpService;
use App\Service\Auth\TwoFactorService;
use App\Service\Auth\WebAuthnService;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use App\Tests\Support\ArraySession;
use App\Tests\Support\VirtualAuthenticator;
use Psr\Log\NullLogger;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Passkey registration, authentication and revocation, through the real
 * ceremony.
 *
 * The authenticator is simulated (see VirtualAuthenticator) but nothing else
 * is: the challenge, the CBOR attestation object, the ES256 signature and every
 * check web-auth/webauthn-lib performs are the production ones. A test that
 * mocked the verifier would pass just as happily against a service that
 * verified nothing.
 */
final class WebAuthnCredentialTest extends DatabaseTestCase
{
    private const APP_URL = 'https://renovo.test';
    private const RP_ID = 'renovo.test';

    private WebAuthnService $webAuthn;
    private RecoveryCodeService $recoveryCodes;
    private TwoFactorService $twoFactor;
    private WebAuthnCredentialRepository $credentials;
    private UserRepository $users;
    private FrozenClock $clock;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-09-14 09:00:00');
        $this->users = new UserRepository($this->db);
        $this->credentials = new WebAuthnCredentialRepository($this->db);

        $audit = new AuditLogService(
            new AuditLogRepository($this->db),
            new MembershipRepository($this->db),
            new RequestContextHolder(RequestContext::of('203.0.113.5', 'PHPUnit')),
            $this->clock,
            new NullLogger(),
        );

        $hasher = new PasswordHasher();
        $totpRepository = new TotpRepository($this->db);
        $this->recoveryCodes = new RecoveryCodeService(
            new RecoveryCodeRepository($this->db),
            $hasher,
            $audit,
            $this->clock,
        );

        $this->twoFactor = new TwoFactorService(
            new TotpService(
                $totpRepository,
                $this->recoveryCodes,
                $this->credentials,
                new SecretCipher(new KeyRing('test-instance-secret-for-totp')),
                $hasher,
                $audit,
                $this->clock,
                new NullLogger(),
            ),
            $this->recoveryCodes,
            $this->credentials,
            $this->users,
            $hasher,
            $audit,
            new ArraySession(),
            $this->clock,
        );

        $this->webAuthn = new WebAuthnService(
            $this->credentials,
            $this->users,
            $totpRepository,
            $this->recoveryCodes,
            $audit,
            $this->clock,
            self::APP_URL,
            'Renovo Test',
        );

        $this->userId = $this->users->create(
            'passkey@example.test',
            'Passkey User',
            'hash',
            false,
            $this->clock->now(),
        );
    }

    public function testRegistersAuthenticatesAndRevokesACredential(): void
    {
        $user = $this->user();
        $authenticator = new VirtualAuthenticator(self::RP_ID, self::APP_URL);

        // --- register ---------------------------------------------------
        $options = $this->webAuthn->registrationOptions($user);
        $challenge = $this->challengeOf($this->webAuthn->serializeOptions($options));

        $credential = $this->webAuthn->completeRegistration(
            $user,
            $authenticator->register($challenge),
            $options,
            'Test key',
            true,
        );

        self::assertSame('Test key', $credential->name);
        self::assertSame($authenticator->credentialIdBase64Url(), $credential->credentialId);
        self::assertTrue($this->webAuthn->hasCredentials($user->id));

        // The handle is generated on first registration and is not the user id.
        $handle = $this->users->webauthnHandle($user->id);
        self::assertNotNull($handle);
        self::assertNotSame((string) $user->id, $handle);

        // --- authenticate, knowing who is signing in --------------------
        $request = $this->webAuthn->authenticationOptions($this->user());
        $requestJson = $this->webAuthn->serializeOptions($request);

        $result = $this->webAuthn->completeAuthentication(
            $authenticator->authenticate($this->challengeOf($requestJson), $handle),
            $request,
            $user->id,
        );

        self::assertSame($user->id, $result['user']->id);

        $stored = $this->credentials->findByCredentialId($credential->credentialId);
        self::assertNotNull($stored);
        self::assertSame(1, $stored->signCount);
        self::assertNotNull($stored->lastUsedAt);

        // --- revoke ------------------------------------------------------
        $this->webAuthn->revoke($this->user(), $credential->id);

        self::assertFalse($this->webAuthn->hasCredentials($user->id));
        self::assertNull($this->credentials->findByCredentialId($credential->credentialId));
    }

    /**
     * The usernameless path: no expected user id, so the credential and the
     * handle it returns are the only things saying who this is.
     */
    public function testAuthenticatesWithoutBeingToldWhoTheUserIs(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $handle = $this->users->webauthnHandle($this->userId);

        $options = $this->webAuthn->authenticationOptions();
        self::assertSame([], $options->allowCredentials);

        $result = $this->webAuthn->completeAuthentication(
            $authenticator->authenticate($this->challengeOf($this->webAuthn->serializeOptions($options)), $handle),
            $options,
        );

        self::assertSame($this->userId, $result['user']->id);
    }

    public function testUsernamelessAssertionWithoutUserVerificationIsRefused(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $handle = $this->users->webauthnHandle($this->userId);

        $options = $this->webAuthn->authenticationOptions();
        self::assertSame(
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            $options->userVerification,
        );

        try {
            $this->webAuthn->completeAuthentication(
                $authenticator->authenticate(
                    $this->challengeOf($this->webAuthn->serializeOptions($options)),
                    $handle,
                    false,
                ),
                $options,
            );
            self::fail('A presence-only assertion must not sign anyone in on its own.');
        } catch (ValidationException $exception) {
            self::assertSame('error.passkey.user_verification_required', $exception->errors()['credential']->key);
        }
    }

    public function testSecondFactorAssertionStillAcceptsPresenceOnly(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $handle = $this->users->webauthnHandle($this->userId);

        $options = $this->webAuthn->authenticationOptions($this->user());
        self::assertSame(
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            $options->userVerification,
        );

        $result = $this->webAuthn->completeAuthentication(
            $authenticator->authenticate(
                $this->challengeOf($this->webAuthn->serializeOptions($options)),
                $handle,
                false,
            ),
            $options,
            $this->userId,
        );

        self::assertSame($this->userId, $result['user']->id);
    }

    public function testRefusesACredentialBelongingToAnotherAccount(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $handle = $this->users->webauthnHandle($this->userId);

        $otherId = $this->users->create('other@example.test', 'Other', 'hash', false, $this->clock->now());
        $options = $this->webAuthn->authenticationOptions($this->user());

        $this->expectException(ValidationException::class);

        $this->webAuthn->completeAuthentication(
            $authenticator->authenticate($this->challengeOf($this->webAuthn->serializeOptions($options)), $handle),
            $options,
            $otherId,
        );
    }

    /**
     * A counter that goes backwards is how a cloned authenticator looks.
     */
    public function testRefusesAnAssertionWhoseSignCountWentBackwards(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $handle = $this->users->webauthnHandle($this->userId);

        $first = $this->webAuthn->authenticationOptions($this->user());
        $this->webAuthn->completeAuthentication(
            $authenticator->authenticate($this->challengeOf($this->webAuthn->serializeOptions($first)), $handle),
            $first,
            $this->userId,
        );

        // Rewind the authenticator: the next assertion claims a counter it has
        // already passed.
        $authenticator->setSignCount(0);

        $second = $this->webAuthn->authenticationOptions($this->user());

        $this->expectException(ValidationException::class);

        $this->webAuthn->completeAuthentication(
            $authenticator->authenticate($this->challengeOf($this->webAuthn->serializeOptions($second)), $handle),
            $second,
            $this->userId,
        );
    }

    public function testRefusesAnAssertionForADifferentChallenge(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $handle = $this->users->webauthnHandle($this->userId);

        $options = $this->webAuthn->authenticationOptions($this->user());
        $someoneElsesChallenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->expectException(ValidationException::class);

        $this->webAuthn->completeAuthentication(
            $authenticator->authenticate($someoneElsesChallenge, $handle),
            $options,
            $this->userId,
        );
    }

    public function testRefusesAResponseFromAnotherOrigin(): void
    {
        $user = $this->user();
        $impostor = new VirtualAuthenticator(self::RP_ID, 'https://not-renovo.test');

        $options = $this->webAuthn->registrationOptions($user);

        $this->expectException(ValidationException::class);

        $this->webAuthn->completeRegistration(
            $user,
            $impostor->register($this->challengeOf($this->webAuthn->serializeOptions($options))),
            $options,
            'Impostor',
        );
    }

    public function testExcludesAlreadyRegisteredCredentialsFromNewRegistrations(): void
    {
        $authenticator = $this->registeredAuthenticator();

        $options = $this->webAuthn->registrationOptions($this->user());

        self::assertCount(1, $options->excludeCredentials);
        self::assertSame(
            $authenticator->credentialIdBase64Url(),
            rtrim(strtr(base64_encode($options->excludeCredentials[0]->id), '+/', '-_'), '='),
        );
    }

    public function testRenamesACredential(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $credential = $this->credentials->findByCredentialId($authenticator->credentialIdBase64Url());
        self::assertNotNull($credential);

        $this->webAuthn->rename($this->user(), $credential->id, 'Renamed key');

        $renamed = $this->credentials->find($this->userId, $credential->id);
        self::assertNotNull($renamed);
        self::assertSame('Renamed key', $renamed->name);
    }

    /**
     * MySQL reports zero changed rows when the form is saved without touching
     * the name; that is still a passkey that exists and is the user's.
     */
    public function testRenamingToTheSameNameSucceeds(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $credential = $this->credentials->findByCredentialId($authenticator->credentialIdBase64Url());
        self::assertNotNull($credential);

        $this->webAuthn->rename($this->user(), $credential->id, 'Same name');
        $this->webAuthn->rename($this->user(), $credential->id, 'Same name');

        self::assertTrue($this->credentials->rename($this->userId, $credential->id, 'Same name'));
        self::assertSame('Same name', $this->credentials->find($this->userId, $credential->id)?->name);
    }

    public function testRenamingAnotherUsersPasskeyIsStillNotFound(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $credential = $this->credentials->findByCredentialId($authenticator->credentialIdBase64Url());
        self::assertNotNull($credential);

        self::assertFalse($this->credentials->rename($this->userId + 1000, $credential->id, 'Taken over'));
        self::assertFalse($this->credentials->rename($this->userId, $credential->id + 1000, 'Nothing here'));
        self::assertNotSame('Taken over', $this->credentials->find($this->userId, $credential->id)?->name);
    }

    /**
     * A passkey is a second factor, so registering one has to come with the
     * same way back in that an authenticator app does. Without this, a user who
     * added a passkey for convenience would have silently made their password
     * login depend on a device with nothing to fall back on.
     */
    public function testRegisteringAPasskeyEarnsTheAccountRecoveryCodes(): void
    {
        self::assertSame(0, $this->recoveryCodes->countUnused($this->userId));

        $this->registeredAuthenticator();

        $codes = $this->twoFactor->ensureRecoveryCodes($this->user());

        self::assertCount(RecoveryCodeService::CODE_COUNT, $codes);
        self::assertSame(RecoveryCodeService::CODE_COUNT, $this->recoveryCodes->countUnused($this->userId));
        self::assertTrue($this->twoFactor->availableMethods($this->userId)['recovery']);
    }

    public function testASecondPasskeyDoesNotReplaceCodesTheUserHasWrittenDown(): void
    {
        $this->registeredAuthenticator();
        $first = $this->twoFactor->ensureRecoveryCodes($this->user());

        // A later registration finds usable codes and leaves them alone.
        self::assertSame([], $this->twoFactor->ensureRecoveryCodes($this->user()));
        self::assertCount(RecoveryCodeService::CODE_COUNT, $first);
    }

    public function testRevokingTheLastPasskeyClearsTheRecoveryCodes(): void
    {
        $authenticator = $this->registeredAuthenticator();
        $this->twoFactor->ensureRecoveryCodes($this->user());

        $credential = $this->credentials->findByCredentialId($authenticator->credentialIdBase64Url());
        self::assertNotNull($credential);

        $this->webAuthn->revoke($this->user(), $credential->id);

        // Nothing left to recover into: codes that outlived every factor would
        // be a standing way past the password on an account that asked for none.
        self::assertSame(0, $this->recoveryCodes->countUnused($this->userId));
        self::assertFalse($this->twoFactor->isRequiredFor($this->userId));
    }

    private function registeredAuthenticator(): VirtualAuthenticator
    {
        $authenticator = new VirtualAuthenticator(self::RP_ID, self::APP_URL);
        $user = $this->user();

        $options = $this->webAuthn->registrationOptions($user);

        $this->webAuthn->completeRegistration(
            $user,
            $authenticator->register($this->challengeOf($this->webAuthn->serializeOptions($options))),
            $options,
            'Registered key',
            true,
        );

        return $authenticator;
    }

    private function user(): \App\Domain\Entity\User
    {
        $user = $this->users->findById($this->userId);
        self::assertNotNull($user);

        return $user;
    }

    /**
     * The challenge as the browser would see it: base64url, straight out of the
     * serialised options.
     */
    private function challengeOf(string $optionsJson): string
    {
        $decoded = json_decode($optionsJson, true);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['challenge']);

        return $decoded['challenge'];
    }
}
