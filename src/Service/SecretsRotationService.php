<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\StoredSecretKind;
use App\Persistence\Database;
use App\Repository\StoredSecretRepository;
use App\Security\KeyRing;
use App\Security\SecretCipher;
use App\Security\UnreadableSecretException;

/**
 * `secrets:rotate`: re-encrypt every stored secret under the current key.
 *
 * Run after replacing `SECRETS_KEY` (the old key moved to
 * `SECRETS_PREVIOUS_KEYS`), and after the first upgrade that sets it. Values
 * already under the current key are skipped, so a second run changes nothing.
 * A value no configured key opens is left exactly as it is and listed by id —
 * the operator may yet find the key — and is never deleted.
 *
 * Each batch is one transaction. A value the application rewrote while the
 * batch was running is left for the next run rather than overwritten.
 */
final class SecretsRotationService
{
    private const BATCH = 200;

    public function __construct(
        private readonly StoredSecretRepository $secrets,
        private readonly KeyRing $keys,
        private readonly Database $db,
        private readonly AuditLogService $audit,
    ) {
    }

    public function usesSecretsKey(): bool
    {
        return $this->keys->usesSecretsKey();
    }

    /**
     * @return array<string, array{rotated: int, current: int, unreadable: list<int>}> Keyed by kind.
     */
    public function rotate(bool $dryRun = false): array
    {
        $report = [];

        foreach (StoredSecretKind::cases() as $kind) {
            $cipher = new SecretCipher($this->keys, $kind->purpose());
            $report[$kind->value] = $this->rotateKind($kind, $cipher, $dryRun);
        }

        if (!$dryRun) {
            $this->audit->recordAnonymous(AuditAction::SecretsRotated, 'console', null, array_map(
                static fn (array $counts): array => [
                    'rotated' => $counts['rotated'],
                    'current' => $counts['current'],
                    'unreadable' => count($counts['unreadable']),
                ],
                $report,
            ));
        }

        return $report;
    }

    /**
     * @return array{rotated: int, current: int, unreadable: list<int>}
     */
    private function rotateKind(StoredSecretKind $kind, SecretCipher $cipher, bool $dryRun): array
    {
        $counts = ['rotated' => 0, 'current' => 0, 'unreadable' => []];
        $after = -1;

        do {
            $batch = $this->secrets->batch($kind, $after, self::BATCH);

            $work = function () use ($batch, $kind, $cipher, $dryRun, &$counts): void {
                foreach ($batch as $row) {
                    if ($row['value'] === '') {
                        continue;
                    }

                    if ($cipher->isCurrent($row['value'])) {
                        $counts['current']++;

                        continue;
                    }

                    try {
                        // A channel config or rate key from before its kind
                        // was encrypted is plaintext, and is encrypted for the
                        // first time. A two-factor seed was always encrypted.
                        $plain = !SecretCipher::isEncrypted($row['value']) && $kind->mayBePlaintext()
                            ? $row['value']
                            : $cipher->decrypt($row['value']);
                    } catch (UnreadableSecretException) {
                        $counts['unreadable'][] = $row['id'];

                        continue;
                    }

                    $replaced = $dryRun
                        || $this->secrets->replace($kind, $row['id'], $row['value'], $cipher->encrypt($plain));
                    if ($replaced) {
                        $counts['rotated']++;
                    }
                }
            };

            $dryRun ? $work() : $this->db->transactional($work);

            $after = $batch === [] ? $after : $batch[count($batch) - 1]['id'];
        } while (count($batch) === self::BATCH);

        return $counts;
    }
}
