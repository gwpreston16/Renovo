<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Palette;
use App\Support\BuildManifest;
use PHPUnit\Framework\TestCase;

/**
 * The design system, as the browser actually receives it.
 *
 * The colours themselves are checked by `ThemeContrastTest`, against the JSON
 * they are authored in. This reads the real output of `npm run build` — the
 * same file a page links — and checks that the JSON arrived intact and is
 * applied, which are the things that would otherwise only be noticed by a
 * person looking at a screen:
 *
 *   - every palette exists in light, in dark and in "system", and an explicit
 *     "light" beats a dark machine;
 *   - the logo's gradient is the mark's alone, and the primary button is the
 *     flat accent with the accent's ink;
 *   - Plus Jakarta Sans is the text face, and `.num` is the only thing that
 *     gives a figure JetBrains Mono;
 *   - no stylesheet but the generated one writes a colour, and no chart does.
 *
 * Skipped when there is no build, so `vendor/bin/phpunit` still runs on a
 * checkout where `npm install` has not been. CI runs the build first, so there
 * it is never skipped.
 */
final class DesignTokensTest extends TestCase
{
    /**
     * The colours a stylesheet is allowed to write.
     *
     * One entry, and it is documented where it is written: the QR code's plate
     * stays white on the dark theme because a camera reads dark modules on a
     * light field.
     *
     * @var array<string, list<string>>
     */
    private const COLOUR_EXCEPTIONS = ['screens.css' => ['#ffffff']];

    private string $css;

    protected function setUp(): void
    {
        $buildPath = dirname(__DIR__, 2) . '/public/build';
        $build = new BuildManifest($buildPath . '/manifest.json', '/build/');

        if (!$build->isBuilt()) {
            self::markTestSkipped('No asset build present. Run "npm install && npm run build".');
        }

        $file = $buildPath . '/' . basename($build->url('app.css'));
        $this->css = (string) file_get_contents($file);
    }

    // ------------------------------------------------------------- the brand

    /** The gradient stops are the logo's, written down once. */
    public function testTheBrandGradientStopsAreDefinedAsTokens(): void
    {
        self::assertSame('#0daa9c', $this->token(':root', '--brand-from'), 'The teal-green stop moved.');
        self::assertSame('#1069bb', $this->token(':root', '--brand-to'), 'The blue stop moved.');

        // The stops name a class each, and the stylesheet gives the class the
        // token: an inline style on the <stop> is refused by the CSP.
        $sprite = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/partials/brand_sprite.twig');
        self::assertStringContainsString('class="brand-stop-from"', $sprite, 'The mark must draw with the stops.');
        self::assertStringContainsString('class="brand-stop-to"', $sprite, 'The mark must draw with the stops.');

        self::assertStringContainsString('.brand-stop-from{stop-color:var(--brand-from)}', $this->css);
        self::assertStringContainsString('.brand-stop-to{stop-color:var(--brand-to)}', $this->css);
    }

    /**
     * The gradient lives on the mark only. Interface elements take the
     * palette's flat accent, which is what lets a palette change them; a
     * gradient on a control is the one colour no palette could reach.
     */
    public function testNoInterfaceElementWearsTheLogoGradient(): void
    {
        // The mark's own two stops are the one place the tokens are drawn.
        $css = str_replace(
            ['.brand-stop-from{stop-color:var(--brand-from)}', '.brand-stop-to{stop-color:var(--brand-to)}'],
            '',
            $this->css,
        );

        self::assertStringNotContainsString('var(--brand-from)', $css);
        self::assertStringNotContainsString('var(--brand-to)', $css);
    }

    /**
     * The primary button is the flat accent carrying the accent's ink — never
     * white on the accent, which on the default emerald is 2.0:1.
     */
    public function testThePrimaryButtonIsTheAccentWithItsInk(): void
    {
        self::assertMatchesRegularExpression(
            '~\.button-primary\{[^}]*background:var\(--accent\)~',
            $this->css,
        );
        self::assertMatchesRegularExpression(
            '~\.button-primary\{[^}]*color:var\(--accent-ink\)~',
            $this->css,
        );
        self::assertDoesNotMatchRegularExpression('~\.button-primary\{[^}]*gradient~', $this->css);
    }

    // ---------------------------------------------------------- the palettes

    /**
     * Every palette, in every theme state.
     *
     * The default palette's blocks are the bare selectors, so a page without
     * a `data-palette` still gets a complete set; every other palette adds its
     * attribute. Each is written for light, for "system" on a dark machine
     * (inside the media query) and for an explicit dark.
     *
     * @return iterable<string, array{string}>
     */
    public static function palettes(): iterable
    {
        foreach (Palette::cases() as $palette) {
            yield $palette->value => [$palette->value];
        }
    }

    /**
     * @dataProvider palettes
     */
    public function testEveryPaletteIsDefinedInLightDarkAndSystem(string $palette): void
    {
        $scope = $palette === Palette::default()->value ? '' : sprintf('[data-palette=%s]', $palette);

        $light = $this->token(':root' . $scope, '--rail');
        $dark = $this->token(':root[data-theme=dark]' . $scope, '--rail');

        self::assertNotNull($light, sprintf('No light block for %s.', $palette));
        self::assertNotNull($dark, sprintf('No explicit-dark block for %s.', $palette));
        self::assertNotSame($light, $dark, sprintf('%s is the same rail in light and dark.', $palette));

        self::assertStringContainsString(
            ':root:not([data-theme=light])' . $scope . '{',
            $this->mediaBlock(),
            sprintf('No system-dark block for %s.', $palette),
        );
    }

    /**
     * An account that has explicitly chosen light, on a machine set to dark,
     * must get light — the `:not([data-theme=light])` guard on the media
     * query. It is the case that silently breaks, because the person who chose
     * the setting is exactly the person whose machine disagrees with it.
     */
    public function testAnExplicitLightBeatsADarkMachine(): void
    {
        self::assertMatchesRegularExpression(
            '~@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root:not\(\[data-theme=["\']?light["\']?\]\)~',
            $this->css,
        );
    }

    /**
     * The picker's swatches are scoped to each option, one per palette, and
     * each carries what its little picture of the app is drawn with.
     */
    public function testEveryPaletteHasASwatch(): void
    {
        foreach (Palette::cases() as $palette) {
            self::assertMatchesRegularExpression(
                sprintf('/\[data-swatch=%s\]\{[^}]*--swatch-canvas:[^}]*--swatch-line:/', $palette->value),
                $this->css,
            );
        }
    }

    // --------------------------------------------------------------- the type

    public function testPlusJakartaSansIsTheTextFace(): void
    {
        self::assertStringContainsStringIgnoringCase(
            'Plus Jakarta Sans Variable',
            (string) $this->token(':root', '--font-text'),
        );
        self::assertMatchesRegularExpression('~html\{[^}]*font-family:var\(--font-text\)~', $this->css);
    }

    /**
     * `.num` is the only rule that sets a figure's face.
     *
     * `code` and the code block use the same monospace, and are code rather
     * than figures; nothing else may name `--font-figures`, or a later screen
     * can give one column of money a different face from the next.
     */
    public function testNumIsTheOnlyWayAFigureGetsItsFace(): void
    {
        self::assertStringContainsStringIgnoringCase(
            'JetBrains Mono Variable',
            (string) $this->token(':root', '--font-figures'),
        );
        self::assertMatchesRegularExpression(
            '~(?:^|[}\s])\.num\{font-family:var\(--font-figures\);font-variant-numeric:tabular-nums\}~',
            $this->css,
        );

        preg_match_all('~([^{}]+)\{[^}]*font-family:var\(--font-figures\)~', $this->css, $matches);
        $selectors = array_map('trim', $matches[1]);

        self::assertSame(['code', '.num', '.code-block'], array_values(array_filter(
            $selectors,
            // Tailwind's own theme layer hands the token to its `font-mono`
            // default; that is a variable, not a rule dressing anything.
            static fn (string $selector): bool => !str_starts_with($selector, '@layer'),
        )));
    }

    /**
     * `font-feature-settings: "tnum"` would switch off every other feature in
     * the font, so the high-level property is the correct one.
     */
    public function testTabularFiguresDoNotClobberTheFontsOtherFeatures(): void
    {
        self::assertDoesNotMatchRegularExpression('~font-feature-settings:[^;}]*tnum~', $this->css);
    }

    // ------------------------------------------------------------- Preflight

    /**
     * Preflight is on, and the defaults the templates rely on came back.
     * Turning the reset on without re-establishing these is how every heading
     * on every page silently becomes body text.
     */
    public function testPreflightIsOnAndTheDefaultsItStripsAreRestored(): void
    {
        self::assertStringContainsString('*,:after,:before', $this->css, 'Preflight is not in the build.');

        $restored = [
            '~[,{}]h1\{font-size:~' => 'Headings lost their size.',
            '~a\{[^}]*text-decoration:underline~' => 'Links lost their underline.',
            '~:focus-visible\{[^}]*outline:2px solid var\(--focus-ring\)~' => 'Nothing shows keyboard focus.',
        ];

        foreach ($restored as $pattern => $message) {
            self::assertMatchesRegularExpression($pattern, $this->css, $message);
        }
    }

    /**
     * A link is accent-coloured *text*, so it is `--accent-text`: the accent
     * itself is a fill, and the default emerald is 2.0:1 as text on white.
     */
    public function testALinkUsesTheAccentsTextShade(): void
    {
        self::assertMatchesRegularExpression('~(?:^|})a\{color:var\(--accent-text\)~', $this->css);
    }

    // ------------------------------------------------------- colour literals

    /**
     * No hand-written stylesheet writes a colour of its own.
     *
     * Every colour arrives through a custom property generated from
     * `tokens.json`, and the contrast test covers every palette × theme of
     * that file — so a screen cannot be right in one palette and wrong in
     * another. One hard `#hex` in a component rule breaks that chain
     * silently: it looks correct in whichever palette its author had on.
     *
     * @dataProvider styleSheets
     */
    public function testOnlyTheTokensWriteAColour(string $file): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/' . $file);

        // Comments are where the palette is explained, hex codes and all.
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\(/', $source, $matches);

        self::assertSame(
            self::COLOUR_EXCEPTIONS[$file] ?? [],
            $matches[0],
            sprintf('%s writes a colour literal. Every colour is a token in assets/theme/tokens.json.', $file),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function styleSheets(): iterable
    {
        yield 'base.css' => ['base.css'];
        yield 'components.css' => ['components.css'];
        yield 'screens.css' => ['screens.css'];
    }

    /**
     * No chart hardcodes a colour. Each reads the tokens through
     * `themeColours()` in `charts.js`, which is the one place a fallback may
     * be written, so a chart follows the palette like everything around it.
     *
     * @dataProvider chartScripts
     */
    public function testNoChartHardcodesAColour(string $file): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/' . $file);
        $source = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|rgba?\(/', $source);
        self::assertStringContainsString('themeColours()', $source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function chartScripts(): iterable
    {
        yield 'category donut' => ['category-donut.js'];
    }

    // ----------------------------------------------------------------- tools

    /** The body of the system-dark media query. */
    private function mediaBlock(): string
    {
        $pattern = '~@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root:not\(~';
        preg_match($pattern, $this->css, $match, PREG_OFFSET_CAPTURE);
        $start = $match[0][1] ?? false;
        self::assertNotFalse($start, 'No system-dark media query.');

        $depth = 0;
        $open = strpos($this->css, '{', $start);
        self::assertNotFalse($open);

        for ($i = $open; $i < strlen($this->css); $i++) {
            $depth += match ($this->css[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return substr($this->css, $open + 1, $i - $open - 1);
            }
        }

        self::fail('The system-dark media query is not closed.');
    }

    /**
     * The value of a custom property in a selector's blocks.
     *
     * The compiled stylesheet is minified and a selector may have several
     * blocks, so every block for the selector is read and the last assignment
     * wins, which is what the cascade would do.
     */
    private function token(string $selector, string $property): ?string
    {
        $value = null;

        foreach ($this->blocks($selector) as $block) {
            if (preg_match('~(?:^|;)' . preg_quote($property, '~') . ':\s*([^;}]+)~', $block, $match) === 1) {
                $value = strtolower(trim($match[1]));
            }
        }

        return $value;
    }

    /**
     * Every declaration block belonging to exactly this selector.
     *
     * Matched by walking braces rather than by a regular expression, so a
     * nested block inside an `@media` rule cannot swallow the closing brace
     * and return half the stylesheet.
     *
     * @return list<string>
     */
    private function blocks(string $selector): array
    {
        $blocks = [];
        $offset = 0;
        $needle = $selector . '{';

        while (($start = strpos($this->css, $needle, $offset)) !== false) {
            $offset = $start + strlen($needle);

            // A selector list such as `:root,:host{` is not this selector, and
            // neither is `:root[data-theme=dark]` when looking for `:root`.
            $before = $start === 0 ? '' : $this->css[$start - 1];
            if ($before !== '' && !in_array($before, ['}', ';', '{'], true)) {
                continue;
            }

            $depth = 1;
            $i = $offset;

            while ($i < strlen($this->css) && $depth > 0) {
                $depth += match ($this->css[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                $i++;
            }

            $blocks[] = substr($this->css, $offset, $i - $offset - 1);
        }

        return $blocks;
    }
}
