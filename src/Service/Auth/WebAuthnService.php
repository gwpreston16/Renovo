<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Domain\Entity\WebAuthnCredential;
use App\Repository\TotpRepository;
use App\Repository\UserRepository;
use App\Repository\WebAuthnCredentialRepository;
use App\Service\AuditLogService;
use App\Service\ValidationException;
use App\Support\Clock;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys and security keys: registration, authentication, naming, revocation.
 *
 * The ceremony itself is web-auth/webauthn-lib's; what lives here is the part
 * that is this application's business — which relying party we are, which
 * credentials a user may present, what gets stored, and what is written to the
 * audit log.
 *
 * Three choices worth stating:
 *
 *  - The relying-party id and the allowed origin both come from APP_URL. A
 *    credential is bound to the RP id it was created under, so this is not a
 *    cosmetic setting: changing APP_URL's host invalidates existing passkeys,
 *    which is exactly the behaviour that stops one deployment's credentials
 *    being usable against another.
 *  - The allowed-origin list is exact (scheme, host and port). An instance
 *    served over plain http — a development box on localhost — therefore works
 *    without a switch that could be left on in production, because the origin
 *    that gets allowed is the one the operator configured.
 *  - The stored credential record is the library's own serialisation. Sign
 *    counts and backup flags live inside it and are updated after every
 *    successful assertion; a counter that goes backwards is refused by the
 *    ceremony, because the only ordinary explanation is a cloned authenticator.
 */
final class WebAuthnService
{
    private const TIMEOUT_MS = 60_000;
    private const HANDLE_BYTES = 32;

    private ?CeremonyStepManagerFactory $ceremonies = null;

    private ?\Symfony\Component\Serializer\SerializerInterface $serializer = null;

    public function __construct(
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly UserRepository $users,
        private readonly TotpRepository $totp,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
        private readonly string $appUrl,
        private readonly string $relyingPartyName,
    ) {
    }

    /**
     * @return list<WebAuthnCredential>
     */
    public function credentialsFor(int $userId): array
    {
        return $this->credentials->findAllForUser($userId);
    }

    public function hasCredentials(int $userId): bool
    {
        return $this->credentials->countForUser($userId) > 0;
    }

    /**
     * Options for registering a new credential.
     *
     * Existing credentials go into `excludeCredentials` so that an authenticator
     * the user has already registered declines rather than silently creating a
     * duplicate they would then have to tell apart in the list.
     */
    public function registrationOptions(User $user): PublicKeyCredentialCreationOptions
    {
        $handle = $this->handleFor($user);

        $exclude = array_map(
            static fn (WebAuthnCredential $credential): PublicKeyCredentialDescriptor
                => PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    self::decodeId($credential->credentialId),
                    $credential->transports,
                ),
            $this->credentials->findAllForUser($user->id),
        );

        return PublicKeyCredentialCreationOptions::create(
            rp: new PublicKeyCredentialRpEntity($this->relyingPartyName, $this->relyingPartyId()),
            user: PublicKeyCredentialUserEntity::create($user->email, $handle, $user->displayName),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                // ES256 first: it is what every platform authenticator produces,
                // and RS256 is here only for older security keys.
                PublicKeyCredentialParameters::create('public-key', -7),
                PublicKeyCredentialParameters::create('public-key', -257),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $exclude,
            timeout: self::TIMEOUT_MS,
        );
    }

    /**
     * Finish registration and store the credential.
     *
     * @param bool $discoverable What the browser reported through the credProps
     *                            extension. Advisory only — an authenticator may
     *                            report nothing — so it labels the list rather
     *                            than gating anything.
     * @throws ValidationException when the browser's response does not verify.
     */
    public function completeRegistration(
        User $user,
        string $responseJson,
        PublicKeyCredentialCreationOptions $options,
        string $name,
        bool $discoverable = false,
    ): WebAuthnCredential {
        $name = $this->normaliseName($name, 'Passkey');

        $credential = $this->parse($responseJson);
        $response = $credential->response;

        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw ValidationException::field('credential', 'error.passkey.not_registration');
        }

        try {
            $record = AuthenticatorAttestationResponseValidator::create(
                $this->ceremonies()->creationCeremony(),
            )->check($response, $options, $this->relyingPartyId());
        } catch (Throwable $exception) {
            throw ValidationException::field(
                'credential',
                'error.passkey.registration_failed',
                ['reason' => $exception->getMessage()],
            );
        }

        $credentialId = self::encodeId($record->publicKeyCredentialId);

        if ($this->credentials->findByCredentialId($credentialId) !== null) {
            throw ValidationException::field('credential', 'error.passkey.already_registered');
        }

        $id = $this->credentials->create(
            userId: $user->id,
            credentialId: $credentialId,
            record: $this->serializer()->serialize($record, 'json'),
            name: $name,
            aaguid: $record->aaguid->toRfc4122(),
            transports: $response->transports,
            signCount: $record->counter,
            isDiscoverable: $discoverable,
            now: $this->clock->now(),
        );

        $this->audit->record(AuditAction::PasskeyRegistered, $user, [
            'name' => $name,
            'credential' => substr($credentialId, 0, 12),
        ]);

        $stored = $this->credentials->find($user->id, $id);
        if ($stored === null) {
            throw new \RuntimeException('The credential could not be read back after registration.');
        }

        return $stored;
    }

    /**
     * Options for an authentication ceremony.
     *
     * With a user, the allow-list names their credentials: that is the
     * second-factor step, where we already know who is signing in. Without one,
     * the list is empty and the browser offers whatever discoverable credential
     * it holds — the usernameless path, where the credential itself says who the
     * user is.
     */
    public function authenticationOptions(?User $user = null): PublicKeyCredentialRequestOptions
    {
        $allow = [];

        if ($user !== null) {
            $allow = array_map(
                static fn (WebAuthnCredential $credential): PublicKeyCredentialDescriptor
                    => PublicKeyCredentialDescriptor::create(
                        PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                        self::decodeId($credential->credentialId),
                        $credential->transports,
                    ),
                $this->credentials->findAllForUser($user->id),
            );
        }

        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->relyingPartyId(),
            allowCredentials: $allow,
            // On its own a passkey is the whole sign-in, so it must prove the
            // person (a PIN or biometric), not only the key's presence (K27).
            // After a password it is a second factor and presence will do.
            userVerification: $user === null
                ? PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED
                : PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: self::TIMEOUT_MS,
        );
    }

    /**
     * Verify an assertion and say which account it proves.
     *
     * @param int|null $expectedUserId The account the caller already believes is
     *                                 signing in — set during the second-factor
     *                                 step, null for a usernameless login. A
     *                                 credential belonging to somebody else is
     *                                 refused rather than silently switching
     *                                 accounts.
     * @return array{user: User, credential: WebAuthnCredential}
     * @throws ValidationException
     */
    public function completeAuthentication(
        string $responseJson,
        PublicKeyCredentialRequestOptions $options,
        ?int $expectedUserId = null,
    ): array {
        $credential = $this->parse($responseJson);
        $response = $credential->response;

        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw ValidationException::field('credential', 'error.passkey.not_authentication');
        }

        $stored = $this->credentials->findByCredentialId(self::encodeId($credential->rawId));
        if ($stored === null) {
            throw ValidationException::field('credential', 'error.passkey.unknown');
        }

        if ($expectedUserId !== null && $stored->userId !== $expectedUserId) {
            throw ValidationException::field('credential', 'error.passkey.other_account');
        }

        $user = $this->users->findById($stored->userId);
        if ($user === null) {
            throw ValidationException::field('credential', 'error.passkey.unknown');
        }

        // Checked against the options this server issued, not the browser's
        // claim: a usernameless sign-in with no user-verified flag is refused.
        if (
            $expectedUserId === null
            && $options->userVerification === PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED
            && !$response->authenticatorData->isUserVerified()
        ) {
            throw ValidationException::field('credential', 'error.passkey.user_verification_required');
        }

        $record = $this->deserializeRecord($stored->record);

        try {
            $updated = AuthenticatorAssertionResponseValidator::create(
                $this->ceremonies()->requestCeremony(),
            )->check(
                $record,
                $response,
                $options,
                $this->relyingPartyId(),
                $expectedUserId === null ? null : $record->userHandle,
            );
        } catch (Throwable $exception) {
            throw ValidationException::field(
                'credential',
                'error.passkey.verification_failed',
                ['reason' => $exception->getMessage()],
            );
        }

        $this->credentials->recordUse(
            $stored->id,
            $this->serializer()->serialize($updated, 'json'),
            $updated->counter,
            $this->clock->now(),
        );

        return ['user' => $user, 'credential' => $stored];
    }

    /**
     * @throws ValidationException
     */
    public function rename(User $user, int $credentialId, string $name): void
    {
        $name = $this->normaliseName($name, 'Passkey');

        if (!$this->credentials->rename($user->id, $credentialId, $name)) {
            throw ValidationException::field('name', 'error.passkey.missing');
        }

        $this->audit->record(AuditAction::PasskeyRenamed, $user, ['name' => $name]);
    }

    /**
     * @throws ValidationException
     */
    public function revoke(User $user, int $credentialId): void
    {
        $credential = $this->credentials->find($user->id, $credentialId);
        if ($credential === null) {
            throw ValidationException::field('credential', 'error.passkey.missing');
        }

        $this->credentials->delete($user->id, $credentialId);

        // Recovery codes outlive any single factor, but not all of them: with
        // no passkey and no authenticator left there is nothing to recover
        // into, and leaving usable codes behind would be a standing way past a
        // password on an account that has asked for none.
        if ($this->credentials->countForUser($user->id) === 0 && !$this->totp->isConfirmed($user->id)) {
            $this->recoveryCodes->clear($user->id);
        }

        $this->audit->record(AuditAction::PasskeyRevoked, $user, ['name' => $credential->name]);
    }

    /**
     * Options are handed to the browser and have to come back unchanged for the
     * challenge check to mean anything, so they are serialised into the session
     * rather than rebuilt.
     */
    public function serializeOptions(
        PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options,
    ): string {
        return $this->serializer()->serialize($options, 'json');
    }

    public function deserializeCreationOptions(string $json): PublicKeyCredentialCreationOptions
    {
        /** @var PublicKeyCredentialCreationOptions */
        return $this->serializer()->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');
    }

    public function deserializeRequestOptions(string $json): PublicKeyCredentialRequestOptions
    {
        /** @var PublicKeyCredentialRequestOptions */
        return $this->serializer()->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');
    }

    /**
     * The host the browser must be on. Derived from APP_URL because a
     * relying-party id that disagreed with the address people actually use
     * would fail every ceremony with a message about origins.
     */
    public function relyingPartyId(): string
    {
        $host = parse_url($this->appUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    private function allowedOrigin(): string
    {
        $parts = parse_url($this->appUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'https://localhost';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * The handle stored inside the authenticator, created on first use.
     */
    private function handleFor(User $user): string
    {
        $existing = $this->users->webauthnHandle($user->id);
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $handle = self::encodeId(random_bytes(self::HANDLE_BYTES));
        $this->users->setWebauthnHandle($user->id, $handle);

        return $handle;
    }

    private function parse(string $responseJson): PublicKeyCredential
    {
        try {
            /** @var PublicKeyCredential */
            return $this->serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');
        } catch (Throwable $exception) {
            throw ValidationException::field('credential', 'error.passkey.unreadable');
        }
    }

    private function deserializeRecord(string $json): CredentialRecord
    {
        /** @var CredentialRecord */
        return $this->serializer()->deserialize($json, CredentialRecord::class, 'json');
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        if ($this->ceremonies === null) {
            $factory = new CeremonyStepManagerFactory();
            $factory->setAllowedOrigins([$this->allowedOrigin()]);
            $factory->setAttestationStatementSupportManager($this->attestationSupport());

            $this->ceremonies = $factory;
        }

        return $this->ceremonies;
    }

    private function attestationSupport(): AttestationStatementSupportManager
    {
        // "none" only. Anything else would mean shipping metadata-service
        // plumbing to check certificate chains this application has no policy
        // about: a self-hosted instance does not care which vendor made the key,
        // only that the same key comes back each time.
        return new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
    }

    private function serializer(): \Symfony\Component\Serializer\SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory($this->attestationSupport()))->create();
    }

    private function normaliseName(string $name, string $fallback): string
    {
        $name = trim($name);

        return $name === '' ? $fallback : mb_substr($name, 0, 100);
    }

    private static function encodeId(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decodeId(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
