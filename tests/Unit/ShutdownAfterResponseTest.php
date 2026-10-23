<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\ShutdownAfterResponse;
use App\Tests\Support\CapturingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Work deferred until after the response (Phase 39) runs in order, and one
 * piece failing is logged without stopping the rest.
 */
final class ShutdownAfterResponseTest extends TestCase
{
    public function testDeferredWorkRunsInOrderAndAFailureIsLoggedNotThrown(): void
    {
        $logger = new CapturingLogger();
        $after = new ShutdownAfterResponse($logger);
        $ran = [];

        $after->defer(static function () use (&$ran): void {
            $ran[] = 'first';
        });
        $after->defer(static function (): void {
            throw new RuntimeException('the destination was down');
        });
        $after->defer(static function () use (&$ran): void {
            $ran[] = 'third';
        });

        $after->run();
        // Run once: the shutdown function registered by defer() finds nothing.
        $after->run();

        self::assertSame(['first', 'third'], $ran);
        self::assertCount(1, $logger->lines);
        self::assertStringContainsString('the destination was down', $logger->lines[0]);
    }
}
