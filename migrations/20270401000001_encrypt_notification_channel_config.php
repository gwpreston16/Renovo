<?php

declare(strict_types=1);

use App\Security\KeyRing;
use App\Security\SecretCipher;
use App\Security\UnreadableSecretException;
use Phinx\Migration\AbstractMigration;

/**
 * Encrypt every notification channel's stored config (Phase 40).
 *
 * The config holds bot tokens, app tokens and webhook URLs that are
 * credentials in themselves. From this release the application writes it as
 * ciphertext; this migration encrypts the rows written before that. Until it
 * runs the application reads an unprefixed row as plain JSON, so channels keep
 * sending in between.
 *
 * The key is resolved exactly as the container resolves it
 * ({@see KeyRing::fromSettings()}). With no key configured the migration stops
 * before touching a row.
 *
 * Idempotent: a row already encrypted (`v1:` or `v2:`) is skipped, and each
 * update only applies if the row still holds the plaintext it read. On MySQL
 * there is no transactional DDL — but this migration is data-only, so a run
 * that stops part-way leaves some rows encrypted and some not, both of which
 * the application reads, and running it again finishes the job.
 *
 * Rolling back decrypts every row to plain JSON again. A row no configured key
 * opens is left as it is and reported.
 */
final class EncryptNotificationChannelConfig extends AbstractMigration
{
    private const BATCH = 200;

    public function up(): void
    {
        $cipher = $this->cipher();

        $this->eachRow(function (int $id, string $config) use ($cipher): void {
            if (SecretCipher::isEncrypted($config)) {
                return;
            }

            $this->execute(
                'UPDATE notification_channels SET config = ? WHERE id = ? AND config = ?',
                [$cipher->encrypt($config), $id, $config],
            );
        });
    }

    public function down(): void
    {
        $cipher = $this->cipher();

        $this->eachRow(function (int $id, string $config) use ($cipher): void {
            if (!SecretCipher::isEncrypted($config)) {
                return;
            }

            try {
                $plain = $cipher->decrypt($config);
            } catch (UnreadableSecretException) {
                $this->getOutput()->writeln(sprintf(
                    '<comment>Notification channel %d could not be decrypted with any configured key; '
                    . 'left as is.</comment>',
                    $id,
                ));

                return;
            }

            $this->execute(
                'UPDATE notification_channels SET config = ? WHERE id = ? AND config = ?',
                [$plain, $id, $config],
            );
        });
    }

    private function cipher(): SecretCipher
    {
        $ring = KeyRing::fromSettings(require dirname(__DIR__) . '/config/settings.php');

        if (!$ring->usesSecretsKey()) {
            $this->getOutput()->writeln(
                '<comment>SECRETS_KEY is not set: channel settings are encrypted under TOTP_ENCRYPTION_KEY or '
                . 'SESSION_KEY. Changing that key will make them unreadable unless the old key is kept in '
                . 'SECRETS_PREVIOUS_KEYS. See docs/configuration.md.</comment>',
            );
        }

        return new SecretCipher($ring, SecretCipher::PURPOSE_CHANNEL_CONFIG);
    }

    /**
     * @param callable(int, string): void $each
     */
    private function eachRow(callable $each): void
    {
        $after = 0;
        do {
            $rows = $this->query(
                'SELECT id, config FROM notification_channels WHERE id > ? ORDER BY id LIMIT ' . self::BATCH,
                [$after],
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $each($after, (string) $row['config']);
            }
        } while (count($rows) === self::BATCH);
    }
}
