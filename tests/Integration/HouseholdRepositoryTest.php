<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\HouseholdRepository;
use App\Repository\UserRepository;

final class HouseholdRepositoryTest extends DatabaseTestCase
{
    /**
     * K110: on a server with `explicit_defaults_for_timestamp` off, the first
     * timestamp of `households` rewrote itself on every UPDATE of the row.
     */
    public function testRenamingAHouseholdKeepsCreatedAt(): void
    {
        $userId = (new UserRepository($this->db))->create('owner@example.test', 'Owner', 'hash');
        $households = new HouseholdRepository($this->db);
        $id = $households->create('Before', $userId);
        $this->db->execute(
            'UPDATE households SET created_at = :at WHERE id = :id',
            ['at' => '2026-01-02 03:04:05', 'id' => $id],
        );

        $households->rename($id, 'After');

        self::assertSame('After', $households->findById($id)?->name);
        self::assertSame(
            '2026-01-02 03:04:05',
            (string) $this->db->fetchValue('SELECT created_at FROM households WHERE id = :id', ['id' => $id]),
        );
    }
}
