<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Persistence\Database;
use App\Persistence\Platform;
use App\Tests\Support\RecordingLogger;
use PDOException;

/**
 * A session setting the server refuses (MySQL 8 without
 * SESSION_VARIABLES_ADMIN, for one) must not stop the application connecting
 * if it is one the platform calls optional, and must if it is not.
 */
final class DatabaseSessionSettingTest extends DatabaseTestCase
{
    private const REFUSED = 'SET refused_by_the_server = 1';

    public function testARefusedSessionSettingIsNotFatal(): void
    {
        $logger = new RecordingLogger();
        $db = $this->databaseWith(optional: [self::REFUSED], logger: $logger);

        self::assertSame(1, (int) $db->fetchValue('SELECT 1'));
        self::assertCount(1, $logger->records);
        self::assertSame('debug', $logger->records[0][0]);
    }

    public function testARefusedSessionSettingIsNotFatalWithoutALogger(): void
    {
        self::assertSame(1, (int) $this->databaseWith(optional: [self::REFUSED])->fetchValue('SELECT 1'));
    }

    public function testARefusedRequiredStatementStillStopsTheConnection(): void
    {
        $this->expectException(PDOException::class);

        $this->databaseWith(optional: [])->connection();
    }

    /**
     * @param list<string> $optional
     */
    private function databaseWith(array $optional, ?RecordingLogger $logger = null): Database
    {
        $real = $this->db->platform();
        $platform = $this->createMock(Platform::class);
        $platform->method('dsn')->willReturnCallback($real->dsn(...));
        $platform->method('connectionOptions')->willReturn($real->connectionOptions());
        $platform->method('initialStatements')->willReturn([...$real->initialStatements(), self::REFUSED]);
        $platform->method('optionalInitialStatements')->willReturn($optional);

        return new Database([
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ], $platform, $logger);
    }
}
