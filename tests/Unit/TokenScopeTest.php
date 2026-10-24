<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\TokenScope;
use App\Domain\TokenScopes;
use PHPUnit\Framework\TestCase;

/**
 * What a token's scopes allow: `:write` implies `:read` for the same resource
 * and nothing else, and the presets the issue form offers are the sets the
 * backfill gives old tokens.
 */
final class TokenScopeTest extends TestCase
{
    public function testWriteImpliesReadForTheSameResourceOnly(): void
    {
        self::assertTrue(TokenScope::SubscriptionsWrite->satisfies(TokenScope::SubscriptionsRead));
        self::assertTrue(TokenScope::SubscriptionsWrite->satisfies(TokenScope::SubscriptionsWrite));
        self::assertFalse(TokenScope::SubscriptionsRead->satisfies(TokenScope::SubscriptionsWrite));
        self::assertFalse(TokenScope::SubscriptionsWrite->satisfies(TokenScope::CatalogueRead));
        self::assertFalse(TokenScope::CatalogueWrite->satisfies(TokenScope::SubscriptionsRead));
        self::assertFalse(TokenScope::CatalogueWrite->satisfies(TokenScope::CalendarRead));
    }

    public function testASetAllowsWhatAnyOfItsScopesSatisfies(): void
    {
        $scopes = TokenScopes::of([TokenScope::SubscriptionsWrite]);

        self::assertTrue($scopes->allows(TokenScope::SubscriptionsRead));
        self::assertTrue($scopes->allows(TokenScope::SubscriptionsWrite));
        self::assertFalse($scopes->allows(TokenScope::CatalogueRead));
        self::assertFalse($scopes->allows(TokenScope::CalendarRead));
        self::assertTrue($scopes->allowsAnyWrite());
        self::assertFalse(TokenScopes::readEverything()->allowsAnyWrite());
    }

    public function testTheStoredFormIsCanonicalAndDropsUnknownNames(): void
    {
        $scopes = TokenScopes::fromString(' calendar:read  admin:everything subscriptions:read calendar:read ');

        self::assertSame('subscriptions:read calendar:read', $scopes->toString());
        self::assertSame(['subscriptions:read', 'calendar:read'], $scopes->values());
        self::assertTrue(TokenScopes::fromString(null)->isEmpty());
        self::assertTrue(TokenScopes::fromString('')->isEmpty());
    }

    public function testThePresetsMatchTheBackfill(): void
    {
        // The strings the Phase 42 backfill writes, so a token issued today
        // from a preset and one migrated from the old ability are the same.
        self::assertSame('subscriptions:read catalogue:read calendar:read', TokenScopes::readEverything()->toString());
        self::assertSame(
            'subscriptions:read subscriptions:write catalogue:read catalogue:write calendar:read',
            TokenScopes::everything()->toString(),
        );
        self::assertSame('calendar:read', TokenScopes::calendarOnly()->toString());
        self::assertTrue(TokenScopes::calendarOnly()->isCalendarOnly());
        self::assertFalse(TokenScopes::readEverything()->isCalendarOnly());
    }

    public function testAPresetIsNamedBackAndAnythingElseIsCustom(): void
    {
        foreach ([TokenScopes::PRESET_READ, TokenScopes::PRESET_WRITE, TokenScopes::PRESET_CALENDAR] as $name) {
            self::assertSame($name, TokenScopes::preset($name)?->presetName());
        }

        self::assertNull(TokenScopes::preset(TokenScopes::PRESET_CUSTOM));
        self::assertNull(TokenScopes::preset('admin'));
        self::assertSame(TokenScopes::PRESET_CUSTOM, TokenScopes::of([TokenScope::CatalogueWrite])->presetName());
        self::assertSame(TokenScopes::PRESET_CUSTOM, TokenScopes::fromValues([])->presetName());
    }

    public function testEveryScopeHasALabel(): void
    {
        foreach (TokenScope::cases() as $scope) {
            self::assertMatchesRegularExpression('/^token_scope\.[a-z]+_(read|write)$/', $scope->labelKey());
        }
    }
}
