<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function min;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function trim;

/**
 * Phase 86's design audit: text a member has to read stays legible.
 *
 * Functional text is at least 11px (0.6875rem): the rail's tagline and group
 * headings, and a calendar chip's name and day total, were 10px. A paused
 * subscription row recedes by muting its text, not by fading the whole row,
 * which took its payment name and status badge below the 4.5:1 contrast
 * WCAG AA asks for.
 *
 * Every rule for a selector is read, media queries included (K235): a later
 * rule that shrinks the text again on some screens would otherwise pass.
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
        self::assertGreaterThanOrEqual(
            0.6875,
            $this->smallestSize($this->css($file), $selector),
            $selector . ' is below 11px in at least one rule',
        );
    }

    /**
     * K235: the check itself reads past the first rule. A second, smaller
     * `.topbar-subtitle` inside a media query is caught, so the test is pinned
     * to what it checks and not only to today's stylesheet.
     */
    public function testASmallerRuleInAMediaQueryFailsTheCheck(): void
    {
        $css = $this->css('components.css') . <<<'CSS'

            @media (min-width: 900px) {
                .topbar-subtitle {
                    font-size: 0.5rem;
                }
            }
            CSS;

        self::assertSame(0.5, $this->smallestSize($css, '.topbar-subtitle'));

        // A last declaration may drop its semicolon; it is still read.
        $css = $this->css('components.css') . <<<'CSS'

            @media (min-width: 900px) {
                .topbar-subtitle { color: inherit; font-size: 0.5rem }
            }
            CSS;
        self::assertSame(0.5, $this->smallestSize($css, '.topbar-subtitle'));
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

    /**
     * Help text and a page's lead-in note share one 60ch rule; the rule is
     * found by the last selector in its list, and help text must be in it.
     */
    public function testAParagraphOfHelpKeepsAReadableMeasure(): void
    {
        self::assertStringContainsString('max-width: 60ch', $this->rule('components.css', 'p.note'));
        self::assertMatchesRegularExpression('/p\.field-hint,\s*p\.note\s*\{/', $this->css('components.css'));
    }

    /** The body of the first rule whose selector list ends with `$selector`. */
    private function rule(string $file, string $selector): string
    {
        $rules = $this->rules($this->css($file), $selector);
        self::assertNotSame([], $rules, $selector . ' not found in ' . $file);

        return $rules[0];
    }

    /**
     * The body of every rule in `$css` whose selector list ends with
     * `$selector`, in order, those inside a media query included.
     *
     * @return list<string>
     */
    private function rules(string $css, string $selector): array
    {
        preg_match_all('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', $css, $matches);

        return $matches[1];
    }

    /**
     * The smallest font size, in rem, that any rule for `$selector` sets.
     * Rules that set no size (a media query changing only spacing) are
     * skipped, but at least one must set it, in rem or through a size token.
     */
    private function smallestSize(string $css, string $selector): float
    {
        $sizes = [];
        foreach ($this->rules($css, $selector) as $rule) {
            // Every declaration, the last one in a rule included (its `;` is optional).
            preg_match_all('/font-size:\s*([^;]+)/', $rule, $values);
            foreach ($values[1] as $value) {
                $value = trim($value);
                // A size token is read from tokens.css, so a token shrunk later fails here too.
                if (preg_match('/^var\((--[a-z-]+)\)$/', $value, $token) === 1) {
                    $value = trim($this->token($token[1]));
                }
                self::assertSame(1, preg_match('/^([0-9.]+)rem$/', $value, $size), $selector . ' sets a rem size');
                $sizes[] = (float) $size[1];
            }
        }
        self::assertNotSame([], $sizes, $selector . ' sets no font size');

        return min($sizes);
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
