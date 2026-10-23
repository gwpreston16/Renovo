<?php

declare(strict_types=1);

/**
 * Phinx configuration.
 *
 * Reads the same environment variables the application does, so migrations
 * always run against the database the app is configured for. Both supported
 * engines are described; DB_DRIVER picks between them.
 */

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
