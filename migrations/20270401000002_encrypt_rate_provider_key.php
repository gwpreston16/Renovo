<?php

declare(strict_types=1);

use App\Security\KeyRing;
use App\Security\SecretCipher;
use App\Security\UnreadableSecretException;
use Phinx\Migration\AbstractMigration;

/**
 * Encrypt the exchange-rate API key stored in instance settings (Phase 40).
 *
 * The same as the channel config backfill, for one row: the key is resolved
 * as the container resolves it, a missing key stops the migration before
 * anything is written, an already encrypted or empty value is left alone, and
 * the application reads an unprefixed value as plain text until this has run.
 * `EXCHANGE_RATE_API_KEY` in the environment is never stored, so it is not
 * touched.
 *
 * Like that backfill, it depends on KeyRing and SecretCipher staying
 * compatible, and builds the cipher only when there is a key to encrypt.
 *
 * Data-only, so on MySQL (no transactional DDL) it is safe to run again.
 * Rolling back stores the key in plain text again.
 */
final class EncryptRateProviderKey extends AbstractMigration
{
    private const KEY = 'rate_provider_key';

    public function up(): void
    {
        $stored = $this->stored();
        if ($stored === null || $stored === '' || SecretCipher::isEncrypted($stored)) {
            return;
        }

        $this->write($this->cipher()->encrypt($stored), $stored);
    }

    public function down(): void
    {
        $stored = $this->stored();
        if ($stored === null || !SecretCipher::isEncrypted($stored)) {
            return;
        }

        try {
            $plain = $this->cipher()->decrypt($stored);
        } catch (UnreadableSecretException) {
            $this->getOutput()->writeln(
                '<comment>The stored rate provider key could not be decrypted with any configured key; '
                . 'left as is.</comment>',
            );

            return;
        }

        $this->write($plain, $stored);
    }

    private function cipher(): SecretCipher
    {
        $ring = KeyRing::fromSettings(require dirname(__DIR__) . '/config/settings.php');

        // An older docker-compose.yml gave this container a placeholder session
        // key. Encrypting under it would leave the rate key unreadable.
        if (in_array($ring->current(), ['unused-by-migrations', 'unused-by-scheduler'], true)) {
            throw new RuntimeException(
                'The migrate container has a placeholder SESSION_KEY. Pass it the real SESSION_KEY '
                . '(or SECRETS_KEY) as docker-compose.yml now does; see docs/upgrading.md.',
            );
        }

        return new SecretCipher($ring, SecretCipher::PURPOSE_RATE_PROVIDER_KEY);
    }

    private function stored(): ?string
    {
        $row = $this->query(
            'SELECT setting_value FROM instance_settings WHERE setting_key = ?',
            [self::KEY],
        )->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (string) $row['setting_value'] : null;
    }

    private function write(string $value, string $was): void
    {
        $this->execute(
            'UPDATE instance_settings SET setting_value = ? WHERE setting_key = ? AND setting_value = ?',
            [$value, self::KEY, $was],
        );
    }
}
