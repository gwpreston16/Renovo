<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;

/**
 * Phase 86's design audit: text a member has to read stays legible.
 *
 * Functional text is at least 11px (0.6875rem): the rail's tagline and group
 * headings, and a calendar chip's name and day total, were 10px. A paused
 * subscription row recedes by muting its text, not by fading the whole row,
 * which took its payment name and status badge below the 4.5:1 contrast
 * WCAG AA asks for.
 */
final class LegibleTextTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function labels(): iterable
    {
        yield 'rail tagline' => ['components.css', '.sidebar-tagline'];
        yield 'rail group heading' => ['components.css', '.sidebar-group-heading'];
        yield 'calendar chip' => ['screens.css', '.calendar-chip'];
        yield 'calendar day total' => ['screens.css', '.calendar-day-total'];
        yield 'top bar subtitle' => ['components.css', '.topbar-subtitle'];
    }

    /** @dataProvider labels */
    public function testFunctionalLabelsAreAtLeastElevenPixels(string $file, string $selector): void
    {
        $rule = $this->rule($file, $selector);

        // A size token is read from tokens.css, so a token shrunk later fails here too.
        if (preg_match('/font-size:\s*var\((--[a-z-]+)\)/', $rule, $token) === 1) {
            $rule = 'font-size: ' . $this->token($token[1]);
        }
        self::assertSame(1, preg_match('/font-size:\s*([0-9.]+)rem/', $rule, $size), $selector . ' sets a rem size');
        self::assertGreaterThanOrEqual(0.6875, (float) $size[1], $selector . ' is below 11px');
    }

    public function testAPausedRowMutesItsTextRatherThanFadingIt(): void
    {
        $text = $this->rule('components.css', '.table tr.is-paused td');
        self::assertStringNotContainsString('opacity', $text);
        self::assertStringContainsString('var(--muted)', $text);
        self::assertSame(
            0,
            preg_match('/\.table tr\.is-paused\s*\{[^}]*opacity/', $this->css('components.css')),
            'the whole paused row must not be faded',
        );
    }

    public function testAParagraphOfHelpKeepsAReadableMeasure(): void
    {
        self::assertStringContainsString('max-width: 60ch', $this->rule('components.css', 'p.field-hint'));
    }

    /** The body of the first rule whose selector list ends with `$selector`. */
    private function rule(string $file, string $selector): string
    {
        $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
        self::assertSame(1, preg_match($pattern, $this->css($file), $match), $selector . ' not found in ' . $file);

        return $match[1];
    }

    private function token(string $name): string
    {
        $pattern = '/' . preg_quote($name, '/') . ':\s*([^;]+);/';
        self::assertSame(1, preg_match($pattern, $this->css('tokens.css'), $match), $name . ' is not a token');

        return $match[1];
    }

    private function css(string $file): string
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/assets/css/' . $file);
        self::assertIsString($css);

        return $css;
    }
}
