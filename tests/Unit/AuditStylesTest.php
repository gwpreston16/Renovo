<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the stylesheet decisions behind the audit's callout and
 * larger-text fixes, which no behavioural test can see: a refactor that put
 * the side stripe back, or let the top bar stop wrapping, would otherwise
 * only show on a screen.
 */
final class AuditStylesTest extends TestCase
{
    public function testACalloutIsTintedNotStripedDownTheLeftEdge(): void
    {
        $rule = $this->rule('components.css', '.callout');

        self::assertStringContainsString('background: var(--warn-bg)', $rule);
        self::assertStringNotContainsString('border-left', $rule);
        // No other callout-family rule draws a coloured left rule either.
        self::assertStringNotContainsString('border-left-color: var(--warn)', $this->css('components.css'));
    }

    public function testTheTopBarWrapsRatherThanOverflowingAtALargerTextSize(): void
    {
        self::assertStringContainsString('flex-wrap: wrap', $this->rule('components.css', '.topbar-actions'));
        self::assertStringContainsString('flex-wrap: wrap', $this->rule('components.css', '.topbar'));
    }

    public function testTheDashboardGridStacksWhenNarrowInRemNotPixels(): void
    {
        $css = $this->css('screens.css');

        self::assertStringContainsString('container-type: inline-size', $this->rule('screens.css', '.bento'));
        self::assertMatchesRegularExpression('/@container \(max-width: \d+rem\)\s*\{[^}]*\.bento-span-2/s', $css);
    }

    private function css(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/' . $file);
    }

    /** The first rule whose selector is exactly `$selector`. */
    private function rule(string $file, string $selector): string
    {
        $found = preg_match('/^' . preg_quote($selector, '/') . ' \{([^}]*)\}/m', $this->css($file), $match);
        self::assertSame(1, $found, 'No rule for ' . $selector . ' in ' . $file);

        return $match[1];
    }
}
