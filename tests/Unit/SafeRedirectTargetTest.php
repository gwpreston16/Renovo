<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\SafeRedirectTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeRedirectTargetTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sitePaths(): array
    {
        return [
            'a path' => ['/subscriptions'],
            'with a query' => ['/subscriptions?sort=name&page=2'],
            'with a fragment' => ['/budgets#members'],
            'the root' => ['/'],
            'a colon later in the path' => ['/calendar/2026-10:day'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherPlaces(): array
    {
        return [
            'empty' => [''],
            'a relative path' => ['subscriptions'],
            'protocol-relative' => ['//evil.example'],
            'backslash after the slash' => ['/\\evil.example'],
            'a backslash further in' => ['/a\\b'],
            'a tab after the slash' => ["/\t/evil.example"],
            'a newline' => ["/ok\nLocation: https://evil.example"],
            'a carriage return' => ["/ok\r"],
            'a null byte' => ["/ok\0"],
            'delete' => ["/ok\x7f"],
            'an absolute URL' => ['https://evil.example'],
            'a scheme-only URL' => ['javascript:alert(1)'],
            'a malformed URL' => ['/:80'],
        ];
    }

    #[DataProvider('sitePaths')]
    public function testASitePathIsKept(string $candidate): void
    {
        self::assertSame($candidate, SafeRedirectTarget::path($candidate));
        self::assertSame($candidate, SafeRedirectTarget::orHome($candidate));
    }

    #[DataProvider('otherPlaces')]
    public function testAnythingElseIsRefused(string $candidate): void
    {
        self::assertNull(SafeRedirectTarget::path($candidate));
        self::assertSame('/', SafeRedirectTarget::orHome($candidate));
    }
}
