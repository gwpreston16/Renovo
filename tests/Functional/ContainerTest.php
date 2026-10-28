<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Http\HttpClient;
use App\Http\HttpClientOptions;
use App\Service\Update\UpdateCheckService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * What production wiring hands each service, which no test that replaces the
 * binding can see.
 */
final class ContainerTest extends TestCase
{
    public function testUpdateCheckGetsTheNarrowedClient(): void
    {
        $container = $this->container();

        // The instance's limits are looser than the check's: 10 s, 5 s, 2 MB.
        $instance = $container->get(HttpClientOptions::class);
        self::assertSame(10, $instance->timeoutSeconds);
        self::assertSame(5, $instance->connectTimeoutSeconds);
        self::assertSame(2 * 1024 * 1024, $instance->maxResponseBytes);

        $client = $container->get(UpdateCheckService::HTTP_CLIENT);
        self::assertInstanceOf(HttpClient::class, $client);
        $options = (new ReflectionProperty(HttpClient::class, 'options'))->getValue($client);
        self::assertInstanceOf(HttpClientOptions::class, $options);

        // A release is a few kilobytes of JSON fetched while an admin waits.
        self::assertSame(5, $options->timeoutSeconds);
        self::assertSame(3, $options->connectTimeoutSeconds);
        self::assertSame(65536, $options->maxResponseBytes);

        // And the service is given that client, not the plain one.
        $service = $container->get(UpdateCheckService::class);
        $given = (new ReflectionProperty(UpdateCheckService::class, 'http'))->getValue($service);
        self::assertSame($client, $given);
    }

    private function container(): ContainerInterface
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $container = $bootstrap(true)->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
