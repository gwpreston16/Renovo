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
    case BackupDestinationSecret = 'backup_destination_secrets';

    /**
     * Whether an unprefixed value is a legacy plaintext one (written before
     * this kind was encrypted) rather than a corrupt one.
     */
    public function mayBePlaintext(): bool
    {
        // A backup destination's credentials were encrypted from the first
        // row, as a two-factor seed was.
        return $this !== self::TotpSecret && $this !== self::BackupDestinationSecret;
    }

    public function purpose(): string
    {
        return match ($this) {
            self::TotpSecret => SecretCipher::PURPOSE_TOTP,
            self::ChannelConfig => SecretCipher::PURPOSE_CHANNEL_CONFIG,
            self::RateProviderKey => SecretCipher::PURPOSE_RATE_PROVIDER_KEY,
            self::BackupDestinationSecret => SecretCipher::PURPOSE_BACKUP_DESTINATION,
        };
    }
}
