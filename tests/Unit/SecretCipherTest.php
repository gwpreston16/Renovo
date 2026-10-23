<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\KeyRing;
use App\Security\SecretCipher;
use App\Security\UnreadableSecretException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretCipherTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $cipher = new SecretCipher(new KeyRing('key-one'));

        $ciphertext = $cipher->encrypt('JBSWY3DPEHPK3PXP');

        self::assertMatchesRegularExpression('/^v2:[0-9a-f]{8}:/', $ciphertext);
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $ciphertext);
        self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($ciphertext));
        self::assertTrue($cipher->isCurrent($ciphertext));
    }

    public function testTamperedCiphertextFails(): void
    {
        $cipher = new SecretCipher(new KeyRing('key-one'));
        $ciphertext = $cipher->encrypt('secret');

        $raw = base64_decode(substr($ciphertext, 12), true);
        self::assertIsString($raw);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);

        $this->expectException(UnreadableSecretException::class);
        $cipher->decrypt(substr($ciphertext, 0, 12) . base64_encode($raw));
    }

    public function testValueUnderAPreviousKeyDecryptsAndIsNotCurrent(): void
    {
        $old = (new SecretCipher(new KeyRing('key-one')))->encrypt('secret');

        $rotated = new SecretCipher(new KeyRing('key-two', ['key-one']));

        self::assertSame('secret', $rotated->decrypt($old));
        self::assertFalse($rotated->isCurrent($old));
        self::assertTrue($rotated->isCurrent($rotated->encrypt('secret')));
    }

    public function testV1ValueDecryptsUnderCurrentOrPreviousKey(): void
    {
        $v1 = self::v1('key-one', 'totp-secret', 'secret');

        self::assertSame('secret', (new SecretCipher(new KeyRing('key-one')))->decrypt($v1));
        self::assertSame('secret', (new SecretCipher(new KeyRing('key-two', ['key-one'])))->decrypt($v1));
    }

    public function testValueUnderAnUnknownKeyRaisesTheDedicatedException(): void
    {
        $ciphertext = (new SecretCipher(new KeyRing('key-one')))->encrypt('secret');

        $this->expectException(UnreadableSecretException::class);
        $this->expectExceptionMessageMatches('/not configured/');
        (new SecretCipher(new KeyRing('key-two')))->decrypt($ciphertext);
    }

    public function testV1ValueNoKeyOpensRaisesTheDedicatedException(): void
    {
        $this->expectException(UnreadableSecretException::class);
        (new SecretCipher(new KeyRing('key-two')))->decrypt(self::v1('key-one', 'totp-secret', 'secret'));
    }

    public function testUnrecognisedAndTruncatedValuesAreUnreadable(): void
    {
        $cipher = new SecretCipher(new KeyRing('key-one'));

        foreach (['plain text', 'v2:short', 'v2:abcdefgh:%%%', 'v1:' . base64_encode('x')] as $value) {
            try {
                $cipher->decrypt($value);
                self::fail('Expected ' . $value . ' to be unreadable.');
            } catch (UnreadableSecretException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTwoPurposesGiveDifferentKeysForTheSameSecret(): void
    {
        $ring = new KeyRing('key-one');
        $totp = new SecretCipher($ring, SecretCipher::PURPOSE_TOTP);
        $channel = new SecretCipher($ring, SecretCipher::PURPOSE_CHANNEL_CONFIG);

        $ciphertext = $totp->encrypt('secret');

        self::assertNotSame(substr($ciphertext, 3, 8), substr($channel->encrypt('secret'), 3, 8));
        $this->expectException(UnreadableSecretException::class);
        $channel->decrypt($ciphertext);
    }

    public function testIsEncryptedRecognisesBothFormats(): void
    {
        self::assertTrue(SecretCipher::isEncrypted('v1:abc'));
        self::assertTrue(SecretCipher::isEncrypted('v2:abcdefgh:abc'));
        self::assertFalse(SecretCipher::isEncrypted('{"token":"abc"}'));
    }

    public function testKeyRingResolvesTheChainFromSettings(): void
    {
        $settings = static fn (string $secrets, string $totp, string $previous = ''): array => [
            'secrets' => ['key' => $secrets, 'previous_keys' => $previous],
            'auth' => ['totp_encryption_key' => $totp],
            'session' => ['key' => 'session'],
        ];

        $ring = KeyRing::fromSettings($settings('secrets', 'totp', 'old-a, old-b,,session'));
        self::assertSame('secrets', $ring->current());
        self::assertSame(['old-a', 'old-b', 'session', 'totp'], $ring->previous());
        self::assertTrue($ring->usesSecretsKey());

        $ring = KeyRing::fromSettings($settings('', 'totp'));
        self::assertSame('totp', $ring->current());
        self::assertSame(['session'], $ring->previous());
        self::assertFalse($ring->usesSecretsKey());

        $ring = KeyRing::fromSettings($settings('', ''));
        self::assertSame('session', $ring->current());
        self::assertSame([], $ring->previous());
    }

    public function testKeyRingRefusesAnEmptyKey(): void
    {
        $this->expectException(RuntimeException::class);
        new KeyRing('');
    }

    /**
     * A value in the format written before the key ring existed.
     */
    private static function v1(string $secret, string $purpose, string $plaintext): string
    {
        $key = hash_hkdf('sha256', $secret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $purpose);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }
}
