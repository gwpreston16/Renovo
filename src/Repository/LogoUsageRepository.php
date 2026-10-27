<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Whether any row on the instance still names a stored logo file.
 *
 * Deliberately not scoped: a file may be named by a row in another household
 * or one the current user cannot see, and deleting it would break that row.
 * It answers yes or no and returns nothing about the row (K120).
 */
final class LogoUsageRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'subscriptions';
    }

    protected function filterableColumns(): array
    {
        return [];
    }

    public function isInUse(string $path): bool
    {
        foreach (['subscriptions', 'payment_methods'] as $table) {
            $found = $this->db->fetchOne(
                'SELECT 1 AS ' . $this->quote('used') . ' FROM ' . $this->quote($table)
                . ' WHERE ' . $this->quote('logo_path') . ' = :path LIMIT 1',
                ['path' => $path],
            );

            if ($found !== null) {
                return true;
            }
        }

        return false;
    }
}
