<?php

declare(strict_types=1);

/**
 * Phinx configuration.
 *
 * Reads the same environment variables the application does, so migrations
 * always run against the database the app is configured for. Both supported
 * engines are described; DB_DRIVER picks between them.
 */

// Phinx does not load config/bootstrap.php, so it pins the zone itself: a
// migration that writes a date runs in UTC like the application (K68).
App\Support\ProcessTimeZone::pin();

App\Support\Environment::loadDotenv(__DIR__);

$env = static function (string $key, string $default = ''): string {
    $value = App\Support\Environment::get($key);

    return ($value === null || $value === '') ? $default : $value;
};

$driver = $env('DB_DRIVER', 'pgsql');
$adapter = $driver === 'mysql' || $driver === 'mariadb' ? 'mysql' : 'pgsql';

$connection = [
    'adapter' => $adapter,
    'host' => $env('DB_HOST', '127.0.0.1'),
    'name' => $env('DB_NAME', 'renovo'),
    'user' => $env('DB_USER', 'renovo'),
    'pass' => $env('DB_PASSWORD', ''),
    'port' => (int) $env('DB_PORT', $adapter === 'mysql' ? '3306' : '5432'),
    'charset' => $adapter === 'mysql' ? 'utf8mb4' : 'utf8',
];

if ($adapter === 'mysql') {
    $connection['collation'] = 'utf8mb4_unicode_ci';

    // Phinx opens its own connection and runs nothing on it, so the session
    // settings the application asks for (see MySqlPlatform) are made here, on
    // a connection handed to Phinx. Two of them, and no more:
    //  - UTC, so a migration or seed reads and writes `timestamp` columns as
    //    the application does. On a server whose own zone is not UTC, the
    //    1.41.0 `timestamp` to `datetime` change otherwise moved every token's
    //    expiry by the server's offset (K238).
    //  - explicit timestamp defaults, without which a table a migration
    //    creates on MariaDB 10.6-10.9 gets a timestamp column that rewrites
    //    itself. The server may refuse it, which is not worth stopping for.
    // The application's strict sql_mode is not run: the migrations keep the
    // server's own. If the server cannot be reached, Phinx is left to say so.
    try {
        $platform = new App\Persistence\MySqlPlatform();
        $pdo = new PDO(
            $platform->dsn($connection),
            $connection['user'],
            $connection['pass'],
        );
        $pdo->exec(App\Persistence\MySqlPlatform::UTC_SESSION);
        foreach ($platform->optionalInitialStatements() as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException) {
                // Refused: nothing is lost that the server did not already have.
            }
        }
        $connection['connection'] = $pdo;
    } catch (PDOException) {
    }
} else {
    // The same for PostgreSQL: Phinx's own connection keeps the server's time
    // zone, so a migration's CURRENT_TIMESTAMP would be written in it. The
    // platform's session settings (`SET TIME ZONE 'UTC'`) are run on a
    // connection handed to Phinx, as the application runs them on its own
    // (K163). If the server cannot be reached, Phinx is left to say so.
    try {
        $platform = new App\Persistence\PostgresPlatform();
        $pdo = new PDO(
            $platform->dsn($connection),
            $connection['user'],
            $connection['pass'],
        );
        foreach ($platform->initialStatements() as $statement) {
            $pdo->exec($statement);
        }
        $connection['connection'] = $pdo;
    } catch (PDOException) {
    }
}

return [
    'paths' => [
        'migrations' => __DIR__ . '/migrations',
        'seeds' => __DIR__ . '/seeds',
    ],
    'environments' => [
        // The applied version is tracked in this table, so a partially applied
        // migration set is detectable with `phinx status`.
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'default',
        'default' => $connection,
        'testing' => $connection,
    ],
    'version_order' => 'creation',
];
