<?php

declare(strict_types=1);

namespace App\Service\Backup;

use SensitiveParameter;

/**
 * BACKUP_ENCRYPTION_KEY, parsed (Phase 38).
 *
 * Thirty-two bytes, written as 64 hexadecimal characters. The value is
 * checked with `ctype_xdigit` before `hex2bin` sees it, so a mistyped key
 * produces this class's message — which never repeats it — rather than a PHP
 * warning that would.
 *
 * The **fingerprint** is the first eight hex characters of the key's SHA-256:
 * enough for an operator to tell which key a backup needs, nothing that helps
 * anybody find the key. It is written into every backup's header and shown on
 * the settings page; the key itself is never stored, logged or shown.
 */
final class BackupKey
{
    public const ENVIRONMENT_VARIABLE = 'BACKUP_ENCRYPTION_KEY';

    /** Kept apart from the raw key, so the same value never serves two uses. */
    private const PURPOSE = 'renovo-instance-backup';

    public readonly string $fingerprint;

    private function __construct(#[SensitiveParameter] private readonly string $bytes)
    {
        $this->fingerprint = substr(hash('sha256', $bytes), 0, 8);
    }

    /**
     * @throws BackupException When the value is not 64 hex characters.
     */
    public static function fromHex(#[SensitiveParameter] string $hex): self
    {
        $hex = trim($hex);

        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            throw new BackupException(
                'key_invalid',
                self::ENVIRONMENT_VARIABLE . ' must be 64 hexadecimal characters (32 bytes). '
                . "Generate one with 'openssl rand -hex 32'.",
            );
        }

        return new self((string) hex2bin($hex));
    }

    /**
     * The key the stream is encrypted under: the configured key through HKDF.
     */
    public function streamKey(): string
    {
        return hash_hkdf('sha256', $this->bytes, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, self::PURPOSE);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['fingerprint' => $this->fingerprint];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A backup key is never serialised.');
    }
}
