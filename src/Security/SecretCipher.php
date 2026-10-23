<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated encryption for the secrets that have to be stored recoverably:
 * two-factor seeds, notification channel config and the exchange-rate API key.
 *
 * None of these can be hashed — the server has to use them — so they sit in the
 * database in recoverable form. Encrypting them does not defend against an
 * attacker who owns the running application (they have the key), and it is not
 * pretended that it does. What it defends against is the realistic case: a
 * database dump, a replica, a backup tarball or a stray `pg_dump` in someone's
 * home directory, none of which carry the environment the key lives in.
 *
 * XChaCha20-Poly1305 via libsodium. Each key in the {@see KeyRing} is put
 * through HKDF with a purpose string, so the same environment value never
 * produces the same subkey for two different uses.
 *
 * Values are written `v2:<kid>:<base64>`, where the key id is the first eight
 * hex characters of a SHA-256 of the derived key: it names the key without
 * revealing it, and cannot be mistyped because nobody types it. `v1:<base64>`
 * values (before the key ring) carry no id and are tried against every key.
 */
final class SecretCipher
{
    public const PURPOSE_TOTP = 'totp-secret';
    public const PURPOSE_CHANNEL_CONFIG = 'channel-config';
    public const PURPOSE_RATE_PROVIDER_KEY = 'rate-provider-key';
    public const PURPOSE_BACKUP_DESTINATION = 'backup-destination';

    private const V1 = 'v1:';
    private const V2 = 'v2:';

    /** @var list<array{kid: string, key: string}> Current key first. */
    private array $keys = [];

    public function __construct(KeyRing $ring, string $purpose = self::PURPOSE_TOTP)
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException(
                'The sodium extension is required to store secrets. '
                . 'Install ext-sodium (it is bundled with PHP 7.2+ on most builds).',
            );
        }

        foreach ([$ring->current(), ...$ring->previous()] as $secret) {
            $key = hash_hkdf('sha256', $secret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $purpose);
            $this->keys[] = ['kid' => substr(hash('sha256', $key), 0, 8), 'key' => $key];
        }
    }

    /**
     * Whether a stored value is ciphertext at all — as opposed to a row written
     * before its kind of secret was encrypted.
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::V1) || str_starts_with($value, self::V2);
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $current = $this->keys[0];

        return self::V2 . $current['kid'] . ':'
            . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $current['key']));
    }

    /**
     * Whether a value is already written under the current key, so
     * `secrets:rotate` can leave it alone.
     */
    public function isCurrent(string $ciphertext): bool
    {
        return str_starts_with($ciphertext, self::V2 . $this->keys[0]['kid'] . ':');
    }

    /**
     * @throws UnreadableSecretException when no key in the ring opens the value.
     *         A secret that will not decrypt is a configuration error — usually
     *         a removed key — and silently treating it as "no second factor"
     *         would turn that error into an account with its 2FA quietly off.
     */
    public function decrypt(string $ciphertext): string
    {
        if (str_starts_with($ciphertext, self::V2)) {
            $rest = substr($ciphertext, strlen(self::V2));
            $kid = substr($rest, 0, 8);
            if (strlen($rest) < 10 || $rest[8] !== ':') {
                throw new UnreadableSecretException('Stored secret is truncated or corrupt.');
            }
            $candidates = array_filter($this->keys, static fn (array $key): bool => $key['kid'] === $kid);
            if ($candidates === []) {
                throw new UnreadableSecretException(sprintf(
                    'A stored secret was encrypted under key %s, which is not configured. '
                    . 'Add that key to SECRETS_PREVIOUS_KEYS.',
                    $kid,
                ));
            }

            return $this->open(substr($rest, 9), $candidates);
        }

        if (str_starts_with($ciphertext, self::V1)) {
            return $this->open(substr($ciphertext, strlen(self::V1)), $this->keys);
        }

        throw new UnreadableSecretException('Stored secret is not in a recognised format.');
    }

    /**
     * @param array<array{kid: string, key: string}> $keys
     */
    private function open(string $encoded, array $keys): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new UnreadableSecretException('Stored secret is truncated or corrupt.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        foreach ($keys as $key) {
            $plaintext = sodium_crypto_secretbox_open($box, $nonce, $key['key']);
            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        throw new UnreadableSecretException(
            'A stored secret could not be decrypted by any configured key. '
            . 'This usually means SECRETS_KEY (or TOTP_ENCRYPTION_KEY or SESSION_KEY) has changed '
            . 'and the old key is not in SECRETS_PREVIOUS_KEYS.',
        );
    }
}
