<?php

declare(strict_types=1);

namespace App\Domain;

use App\Security\SecretCipher;

/**
 * The kinds of secret the application stores encrypted, each under its own
 * purpose so one key never yields the same subkey twice.
 */
enum StoredSecretKind: string
{
    case TotpSecret = 'totp_secrets';
    case ChannelConfig = 'channel_configs';
    case RateProviderKey = 'rate_provider_key';

    /**
     * Whether an unprefixed value is a legacy plaintext one (written before
     * this kind was encrypted) rather than a corrupt one.
     */
    public function mayBePlaintext(): bool
    {
        return $this !== self::TotpSecret;
    }

    public function purpose(): string
    {
        return match ($this) {
            self::TotpSecret => SecretCipher::PURPOSE_TOTP,
            self::ChannelConfig => SecretCipher::PURPOSE_CHANNEL_CONFIG,
            self::RateProviderKey => SecretCipher::PURPOSE_RATE_PROVIDER_KEY,
        };
    }
}
