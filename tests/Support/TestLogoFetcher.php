<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Persistence\Database;
use App\Repository\LogoCacheRepository;
use App\Service\LogoFetcher;
use App\Service\LogoStorage;
use App\Support\Clock;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;

/**
 * A logo fetcher with a fake network behind it.
 *
 * Most tests only need one that never finds anything — a subscription must
 * save whether or not its site has an icon — so `silent()` is the default. The
 * cache test builds its own with a recording client.
 */
final class TestLogoFetcher
{
    public static function silent(Database $db, Clock $clock): LogoFetcher
    {
        return self::with(FakeGuardedClient::returning('', 404), $db, $clock);
    }

    /** The logo storage the fetcher writes to, for a service that releases logos. */
    public static function storage(): LogoStorage
    {
        return new LogoStorage(sys_get_temp_dir() . '/renovo-logo-test/public', 1024 * 1024);
    }

    public static function with(FakeGuardedClient $client, Database $db, Clock $clock): LogoFetcher
    {
        $directory = sys_get_temp_dir() . '/renovo-logo-test';

        return new LogoFetcher(
            $client,
            new RequestFactory(),
            new LogoCacheRepository($db),
            new LogoStorage($directory . '/public', 1024 * 1024),
            $clock,
            new NullLogger(),
            $directory . '/cache',
        );
    }
}
