<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * The keys stored secrets are encrypted under: one current key that every new
 * value is written with, and the keys it replaced, kept for reading only.
 *
 * This is the one place the key chain is resolved. The container, the backfill
 * migrations and `secrets:rotate` all build their ring through
 * {@see fromSettings()}, so they cannot disagree about which key is current.
 *
 * The current key is `SECRETS_KEY`, falling back to `TOTP_ENCRYPTION_KEY` and
 * then `SESSION_KEY`, so an instance upgrades without new configuration. The
 * two legacy keys are always kept as previous keys too: an operator who sets
 * `SECRETS_KEY` for the first time keeps every value written before it, and
 * `secrets:rotate` moves those values across.
 *
 * A KMS or HSM backend would replace this class's constructor arguments, not
 * the cipher.
 */
final class KeyRing
{
    /** @var list<string> */
    private array $previous;

    /**
     * @param list<string> $previous Decryption-only keys, oldest last.
     */
    public function __construct(
        #[SensitiveParameter] private readonly string $current,
        #[SensitiveParameter] array $previous = [],
        private readonly bool $fromSecretsKey = true,
    ) {
        if ($current === '') {
            throw new RuntimeException(
                'No encryption key is configured. Set SECRETS_KEY or SESSION_KEY (see .env.example).',
            );
        }

        $this->previous = array_values(array_unique(array_filter(
            $previous,
            static fn (string $key): bool => $key !== '' && $key !== $current,
        )));
    }

    /**
     * @param array<string, mixed> $settings The application settings (config/settings.php).
     */
    public static function fromSettings(array $settings): self
    {
        $secretsKey = self::string($settings['secrets']['key'] ?? '');
        $totpKey = self::string($settings['auth']['totp_encryption_key'] ?? '');
        $sessionKey = self::string($settings['session']['key'] ?? '');

        $current = $secretsKey !== '' ? $secretsKey : ($totpKey !== '' ? $totpKey : $sessionKey);

        $listed = array_map('trim', explode(',', self::string($settings['secrets']['previous_keys'] ?? '')));

        return new self($current, [...$listed, $totpKey, $sessionKey], $secretsKey !== '');
    }

    public function current(): string
    {
        return $this->current;
    }

    /**
     * @return list<string>
     */
    public function previous(): array
    {
        return $this->previous;
    }

    /**
     * Whether `SECRETS_KEY` is set. When it is not, the current key is
     * `TOTP_ENCRYPTION_KEY` or `SESSION_KEY`, and rotating the session key
     * would make every stored secret unreadable — Instance status says so.
     */
    public function usesSecretsKey(): bool
    {
        return $this->fromSecretsKey;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
