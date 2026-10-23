<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\Database;
use App\Persistence\PlatformFactory;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use SensitiveParameter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The empty database a restore rehearsal restores into and empties again
 * (Phase 39), from `BACKUP_REHEARSAL_DB_*`.
 *
 * Everything the rehearsal does to it that is SQL is here, so the rule that
 * persistence lives in the repository layer holds for it too: whether it is
 * empty, bringing it to a migration version, and dropping every table again.
 *
 * It is refused when it is the application's own database — the same
 * engine, host, port and name — however the two are spelt.
 */
final class ScratchDatabase
{
    private ?Database $db = null;

    /**
     * @param array{driver: string, host: string, port: int, name: string, user: string, password: string} $config
     * @param array{driver: string, host: string, port: int, name: string} $application
     */
    public function __construct(
        #[SensitiveParameter] private readonly array $config,
        private readonly array $application,
        private readonly string $migrationsPath,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->config['name']) !== '' && trim($this->config['host']) !== '';
    }

    /**
     * Whether it names the application's own database.
     */
    public function isTheApplicationDatabase(): bool
    {
        $engine = static fn (string $driver): string
            => in_array(strtolower(trim($driver)), ['mysql', 'mariadb'], true) ? 'mysql' : 'pgsql';
        $host = static function (string $host): string {
            $host = strtolower(trim($host, " []\t"));

            return in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? 'loopback' : $host;
        };

        return $engine($this->config['driver']) === $engine($this->application['driver'])
            && $host($this->config['host']) === $host($this->application['host'])
            && $this->config['port'] === $this->application['port']
            && strtolower(trim($this->config['name'])) === strtolower(trim($this->application['name']));
    }

    public function database(): Database
    {
        return $this->db ??= new Database(
            [
                'driver' => $this->config['driver'],
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'name' => $this->config['name'],
                'user' => $this->config['user'],
                'password' => $this->config['password'],
                'charset' => 'utf8',
            ],
            PlatformFactory::create($this->config['driver']),
        );
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return (new BackupDumper($this->database()))->existingTables();
    }

    /**
     * Bring it to a migration version with the application's own
     * migrations, as `phinx migrate -t` would.
     */
    public function migrateTo(string $version): void
    {
        $mysql = $this->database()->platform()->name() === 'mysql';
        $connection = [
            'adapter' => $mysql ? 'mysql' : 'pgsql',
            'host' => $this->config['host'],
            'name' => $this->config['name'],
            'user' => $this->config['user'],
            'pass' => $this->config['password'],
            'port' => $this->config['port'],
            'charset' => $mysql ? 'utf8mb4' : 'utf8',
        ];
        if ($mysql) {
            $connection['collation'] = 'utf8mb4_unicode_ci';
        }

        $manager = new Manager(
            new Config([
                'paths' => ['migrations' => $this->migrationsPath],
                'environments' => [
                    'default_migration_table' => BackupDumper::MIGRATIONS_TABLE,
                    'default_environment' => 'rehearsal',
                    'rehearsal' => $connection,
                ],
                'version_order' => 'creation',
            ]),
            new ArrayInput([]),
            new NullOutput(),
        );
        $manager->migrate('rehearsal', (int) $version);
    }

    /**
     * Drop every table in it, leaving it as empty as it was found.
     */
    public function dropAll(): void
    {
        $db = $this->database();
        $platform = $db->platform();
        $mysql = $platform->name() === 'mysql';

        if ($mysql) {
            $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        }
        try {
            foreach ($this->tables() as $table) {
                $db->execute(
                    'DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table) . ($mysql ? '' : ' CASCADE'),
                );
            }
        } finally {
            if ($mysql) {
                $db->execute('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }
}
