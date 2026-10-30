<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Palette;
use PHPUnit\Framework\TestCase;

/**
 * Every palette, in light and in dark, is readable.
 *
 * Reads `assets/theme/tokens.json` — the same file the build turns into the
 * stylesheet — so what is checked here and what is shipped cannot drift
 * apart, and the check needs no `npm run build`. Each palette × theme is
 * resolved in the one order the generator uses (base light, base dark,
 * palette light, palette dark; later wins; `@name` references replaced by
 * their values), then every pair of colours a rule actually puts together is
 * measured against WCAG 2.1 AA.
 *
 * A translucent colour is measured as it appears: composited onto what it
 * sits on. A dark theme's badge background is 16% red over a card, not 16%
 * red over nothing, and a contrast ratio for "16% red" alone means nothing.
 *
 * Every pair below is one the stylesheets genuinely produce. A pair that
 * stops being real should be deleted from here rather than left to pass, and
 * a failure is fixed in `tokens.json` — keeping the hue, moving the lightness
 * — never by loosening a threshold here.
 */
final class ThemeContrastTest extends TestCase
{
    /** WCAG 2.1 AA: normal-sized text against its background. */
    private const AA_TEXT = 4.5;

    /**
     * WCAG 2.1 AA: large text, and the graphical objects and component
     * boundaries a reader has to find — a focus ring, a field's edge, a chart
     * segment. Every use of large text in the application (the KPI, the page
     * title) is set in a colour already held to AA_TEXT below, so this bound
     * applies to the graphics.
     */
    private const AA_NON_TEXT = 3.0;

    /**
     * Ink => the grounds it is drawn on, at AA_TEXT.
     *
     * A ground that is translucent is composited onto the ground named after
     * the colon — `ok-bg:surface` is the ok tint as it appears on a card.
     */
    private const TEXT_PAIRS = [
        // Ordinary text, wherever text is set: the page, a card, a hovered row
        // or a field, a tinted status row, the accent's soft wash.
        'text' => [
            'bg', 'surface', 'surface-2',
            'ok-bg:surface', 'warn-bg:surface', 'bad-bg:surface', 'info-bg:surface', 'accent-soft:surface',
        ],
        'muted' => [
            'bg', 'surface', 'surface-2',
            'ok-bg:surface', 'warn-bg:surface', 'bad-bg:surface', 'info-bg:surface', 'accent-soft:surface',
        ],
        // Faint text, and a disabled button's label on its fill (K158).
        'faint' => ['bg', 'surface', 'surface-2'],

        // The accent rule: a filled accent carries its ink, and accent-coloured
        // text is always the accent's text shade.
        'accent-ink' => ['accent', 'accent-hover'],
        'accent-text' => ['bg', 'surface', 'surface-2', 'accent-soft:surface'],

        // Each semantic ink on its own tint, and on the surfaces it is also
        // used on bare (a price that went up, a peak month, a field error).
        'ok' => ['ok-bg:surface', 'surface'],
        'warn' => ['warn-bg:surface', 'bg', 'surface', 'surface-2'],
        'bad' => ['bad-bg:surface', 'bg', 'surface', 'surface-2'],
        'info' => ['info-bg:surface', 'surface'],

        // The secondary button.
        'ink-btn-text' => ['ink-btn', 'ink-btn-hover'],

        // The rail, on the rail. An item's label sits on the rail, on the
        // hover tint and on the active fill; the quieter inks only on the rail.
        'rail-ink' => ['rail', 'rail-tint:rail', 'rail-active:rail'],
        'rail-text' => ['rail', 'rail-tint:rail', 'rail-active:rail'],
        // The household label's second line sits on the label's tint.
        'rail-muted' => ['rail', 'rail-tint:rail'],
        'rail-faint' => ['rail'],
        // Accent-coloured text on the rail: the tagline under the brand, and
        // the Subscriptions count on its active-fill pill.
        'rail-accent' => ['rail', 'rail-active:rail'],
        'rail-bad' => ['rail'],
        'rail-info' => ['rail'],

        // A member's placeholder: each tone's letter on its own disc.
        'avatar-1-ink' => ['avatar-1'],
        'avatar-2-ink' => ['avatar-2'],
        'avatar-3-ink' => ['avatar-3'],
        'avatar-4-ink' => ['avatar-4'],
        'avatar-5-ink' => ['avatar-5'],
        'avatar-6-ink' => ['avatar-6'],
        'avatar-7-ink' => ['avatar-7'],
        'avatar-8-ink' => ['avatar-8'],
    ];

    /** Graphic => the grounds it has to be found on, at AA_NON_TEXT. */
    private const NON_TEXT_PAIRS = [
        'focus-ring' => ['bg', 'surface', 'surface-2'],
        // The ring and the active item's icon on the rail; the signed-out
        // brand panel's feature icons on their tile.
        'rail-accent' => ['rail', 'rail-active:rail', 'rail-tint:rail'],
        // The edge of a filter chip and of the toggle's track, found on each
        // ground they are set on.
        'border-control' => ['bg', 'surface', 'surface-2'],
        // Not `border-field`: a text field's edge is deliberately lighter than
        // this bound, and the field is found by its fill and label instead.
        // Chart series, on the card a chart is drawn on.
        's1' => ['surface'],
        's2' => ['surface'],
        's3' => ['surface'],
        's4' => ['surface'],
        's5' => ['surface'],
        's6' => ['surface'],
        's-other' => ['surface'],
        // A member's bar on Who pays what, on the meter's track.
        'avatar-1-ink' => ['surface-2'],
        'avatar-2-ink' => ['surface-2'],
        'avatar-3-ink' => ['surface-2'],
        'avatar-4-ink' => ['surface-2'],
        'avatar-5-ink' => ['surface-2'],
        'avatar-6-ink' => ['surface-2'],
        'avatar-7-ink' => ['surface-2'],
        'avatar-8-ink' => ['surface-2'],
    ];

    private const SERIES = ['s1', 's2', 's3', 's4', 's5', 's6', 's-other'];

    /** @var array<string, mixed>|null */
    private static ?array $tokens = null;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function palettesAndThemes(): iterable
    {
        foreach (array_keys(self::tokens()['palettes']) as $palette) {
            foreach (['light', 'dark'] as $theme) {
                yield sprintf('%s %s', $palette, $theme) => [(string) $palette, $theme];
            }
        }
    }

    /**
     * @dataProvider palettesAndThemes
     */
    public function testEveryTextPairClearsAa(string $palette, string $theme): void
    {
        $this->assertPairs($palette, $theme, self::TEXT_PAIRS, self::AA_TEXT);
    }

    /**
     * @dataProvider palettesAndThemes
     */
    public function testEveryRingEdgeAndSeriesIsVisible(string $palette, string $theme): void
    {
        $this->assertPairs($palette, $theme, self::NON_TEXT_PAIRS, self::AA_NON_TEXT);
    }

    /**
     * No two chart series are the same colour. A categorical palette exists to
     * tell one category from another; a repeat is a segment nobody can find.
     *
     * @dataProvider palettesAndThemes
     */
    public function testEverySeriesIsDistinct(string $palette, string $theme): void
    {
        $values = self::resolve($palette, $theme);
        $series = array_map(static fn (string $name): string => strtolower($values[$name]), self::SERIES);

        self::assertSame(
            count($series),
            count(array_unique($series)),
            sprintf('A chart series repeats a colour in %s %s: %s', $palette, $theme, implode(', ', $series)),
        );
    }

    /**
     * Every palette × theme defines the same set of tokens.
     *
     * The stylesheet writes each combination out in full, so a token that one
     * palette forgot is a `var()` that resolves to nothing on exactly the
     * pages of the people who chose that palette.
     */
    public function testEveryCombinationDefinesTheSameTokens(): void
    {
        $expected = array_keys(self::resolve(Palette::default()->value, 'light'));
        sort($expected);

        foreach (self::palettesAndThemes() as $label => [$palette, $theme]) {
            $keys = array_keys(self::resolve($palette, $theme));
            sort($keys);

            self::assertSame($expected, $keys, sprintf('%s defines a different set of tokens.', $label));
        }
    }

    /**
     * The palettes a member can choose are exactly the palettes that exist.
     * One without the other is a picker option that renders unstyled, or a
     * palette nobody can reach.
     */
    public function testThePaletteAllowlistMatchesTheTokens(): void
    {
        $defined = array_keys(self::tokens()['palettes']);
        $allowed = array_map(static fn (Palette $palette): string => $palette->value, Palette::cases());

        sort($defined);
        sort($allowed);

        self::assertSame($defined, $allowed);
    }

    /**
     * Section I of Phase 18: the prototype values that failed AA, fixed. These
     * are covered by the matrix above as well; they are named here so that
     * reverting one to the prototype's value fails with a message that says
     * which fix was undone.
     */
    public function testThePrototypesFailingPairsWereFixed(): void
    {
        $light = self::resolve('navy', 'light');
        self::assertGreaterThanOrEqual(self::AA_TEXT, $this->ratio($light['bad'], $light['bad-bg']), 'bad on bad-bg');

        $ocean = self::resolve('ocean', 'dark');
        self::assertGreaterThanOrEqual(
            self::AA_TEXT,
            $this->ratio($ocean['accent-ink'], $ocean['accent']),
            'Ocean dark: the ink on the accent',
        );

        foreach (['paper', 'ocean', 'navy', 'forest'] as $palette) {
            $values = self::resolve($palette, 'light');
            self::assertGreaterThanOrEqual(
                self::AA_TEXT,
                $this->ratio($values['rail-faint'], $values['rail']),
                sprintf('%s: rail-faint on the rail', $palette),
            );
        }
    }

    // ------------------------------------------------------------- the checks

    /**
     * @param array<string, list<string>> $pairs
     */
    private function assertPairs(string $palette, string $theme, array $pairs, float $minimum): void
    {
        $values = self::resolve($palette, $theme);
        $failures = [];

        foreach ($pairs as $ink => $grounds) {
            foreach ($grounds as $ground) {
                [$name, $under] = array_pad(explode(':', $ground, 2), 2, null);

                $background = $under === null
                    ? $values[$name]
                    : $this->composite($values[$name], $values[$under]);
                $foreground = $this->composite($values[$ink], $background);

                $ratio = $this->ratio($foreground, $background);

                if ($ratio < $minimum) {
                    $failures[] = sprintf(
                        '%s (%s) on %s (%s): %.2f:1',
                        $ink,
                        $values[$ink],
                        $ground,
                        $background,
                        $ratio,
                    );
                }
            }
        }

        self::assertSame(
            [],
            $failures,
            sprintf(
                '%s %s has pairs under %.1f:1:%s  %s',
                $palette,
                $theme,
                $minimum,
                PHP_EOL,
                implode(PHP_EOL . '  ', $failures),
            ),
        );
    }

    // -------------------------------------------------------------- resolving

    /**
     * @return array<string, mixed>
     */
    private static function tokens(): array
    {
        if (self::$tokens === null) {
            $json = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/theme/tokens.json');
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            self::$tokens = $decoded;
        }

        return self::$tokens;
    }

    /**
     * One palette in one theme, as `assets/theme/build-tokens.js` resolves it.
     *
     * @return array<string, string>
     */
    private static function resolve(string $palette, string $theme): array
    {
        $tokens = self::tokens();
        $set = $tokens['palettes'][$palette];

        $layers = $theme === 'dark'
            ? [$tokens['base']['light'], $tokens['base']['dark'], $set['light'], $set['dark']]
            : [$tokens['base']['light'], $set['light']];

        /** @var array<string, string> $merged */
        $merged = array_merge(...$layers);

        foreach ($merged as $name => $value) {
            $seen = [];

            while (str_starts_with($value, '@')) {
                $target = substr($value, 1);
                self::assertArrayHasKey($target, $merged, sprintf('"%s" refers to a missing token.', $name));
                self::assertNotContains($target, $seen, sprintf('"%s" is a circular reference.', $name));
                $seen[] = $target;
                $value = $merged[$target];
            }

            $merged[$name] = $value;
        }

        return $merged;
    }

    // ------------------------------------------------------------------ colour

    /**
     * A colour as it appears over an opaque background, as `#rrggbb`.
     *
     * An opaque colour comes back as itself; a translucent one is blended.
     */
    private function composite(string $colour, string $over): string
    {
        [$r, $g, $b, $a] = $this->rgba($colour);

        if ($a >= 1.0) {
            return sprintf('#%02x%02x%02x', $r, $g, $b);
        }

        [$br, $bg, $bb, $ba] = $this->rgba($over);
        self::assertSame(1.0, $ba, sprintf('Cannot composite onto a translucent ground (%s).', $over));

        return sprintf(
            '#%02x%02x%02x',
            (int) round($r * $a + $br * (1 - $a)),
            (int) round($g * $a + $bg * (1 - $a)),
            (int) round($b * $a + $bb * (1 - $a)),
        );
    }

    /**
     * @return array{int, int, int, float}
     */
    private function rgba(string $colour): array
    {
        $colour = strtolower(trim($colour));

        if (preg_match('~^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$~', $colour, $m) === 1) {
            return [(int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) ? (float) $m[4] : 1.0];
        }

        $hex = ltrim($colour, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        self::assertMatchesRegularExpression('~^[0-9a-f]{6}$~', $hex, sprintf('"%s" is not a colour.', $colour));

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
            1.0,
        ];
    }

    /** The WCAG 2.1 contrast ratio between two opaque colours. */
    private function ratio(string $a, string $b): float
    {
        $first = $this->luminance($a);
        $second = $this->luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    /** Relative luminance, per the WCAG definition. */
    private function luminance(string $colour): float
    {
        [$r, $g, $b] = $this->rgba($colour);

        $linear = static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }
}
