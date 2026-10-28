<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function array_map;
use function basename;
use function dirname;
use function file_get_contents;
use function implode;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function sprintf;
use function str_repeat;
use function str_contains;
use function substr_count;

/**
 * The rules the templates are held to, read off the templates themselves.
 *
 * Everything here is a property of the source rather than of a rendered page,
 * which is the point: a screen only has to be rendered to be checked once
 * somebody has written a test for that screen, and the failures below are the
 * kind that arrive on whichever screen nobody thought to cover. Reading the
 * whole directory means a template added next year is checked the day it is
 * added.
 *
 * What is checked, and why each one is worth a test rather than a review:
 *
 *   - **No money, number or percent written as a literal.** A `£` or a `9.99`
 *     in a template is correct in English and wrong in every locale that
 *     groups thousands with a space or marks the decimal with a comma. It is
 *     also invisible in review, because it looks exactly like the right
 *     answer.
 *   - **Every question a destructive control asks comes from the catalogue.**
 *     A `data-confirm="Delete this?"` is a user-visible string that no translator
 *     will ever see and the completeness check cannot count.
 *   - **Every table can scroll.** A table is the one element that refuses to
 *     be laid out narrower than its content, so one without a wrapper does not
 *     overflow tidily — it widens the whole page on a phone.
 *   - **One filled button per template.** The rendered-page half of this lives
 *     in ShellTest; this half catches the screens that test does not render,
 *     and catches them in the file being edited rather than three screens away.
 */
final class TemplateConventionsTest extends TestCase
{
    /**
     * Currency signs, as distinct from the letters some currencies are written
     * in: a template saying "USD" in a heading is a word, and a template
     * saying "$" is a formatting decision that belongs to ICU.
     */
    private const CURRENCY_SIGNS = '£$€¥₹₽₩¢';

    /**
     * Attributes whose values are lengths, coordinates or ratios rather than
     * anything a reader is shown: a `1.5rem` or a `0 0 460 427` is not a
     * number the locale has an opinion about.
     */
    private const GEOMETRY_ATTRIBUTES = [
        'data-css-width',
        'data-css-height',
        'data-css-left',
        'data-css-top',
        'data-css-bottom',
        'viewBox',
        'd',
        'width',
        'height',
        'stroke-width',
        'pattern',
    ];

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function templates(): iterable
    {
        $root = dirname(__DIR__, 2) . '/templates';

        /** @var array<int, string> $paths */
        $paths = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($directory as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'twig') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        foreach ($paths as $path) {
            yield substr($path, strlen($root) + 1) => [$path];
        }
    }

    /**
     * @dataProvider templates
     */
    public function testNoTemplateWritesACurrencySignOfItsOwn(string $path): void
    {
        $matches = [];
        preg_match_all(
            sprintf('/[%s]/u', preg_quote(self::CURRENCY_SIGNS, '/')),
            $this->markup($path),
            $matches,
        );

        self::assertSame(
            [],
            $matches[0],
            sprintf(
                '%s writes a currency sign. The sign a reader sees is chosen by ICU from their locale '
                . 'and the amount\'s currency — see the `money` filter.',
                basename($path),
            ),
        );
    }

    /**
     * @dataProvider templates
     */
    public function testNoTemplateWritesANumberInAParticularLocalesShape(string $path): void
    {
        $matches = [];
        preg_match_all('/(?<![\w-])\d{1,3}(?:[.,]\d+)+(?![\w%-])/', $this->markup($path), $matches);

        self::assertSame(
            [],
            $matches[0],
            sprintf(
                '%s writes a number with its own decimal mark. "9.99" is "9,99" to a French reader; '
                . 'put the value through the `decimal` filter and let ICU shape it.',
                basename($path),
            ),
        );
    }

    /**
     * @dataProvider templates
     */
    public function testNoTemplateWritesAPercentSignOfItsOwn(string $path): void
    {
        $matches = [];
        preg_match_all('/%/', $this->markup($path), $matches);

        self::assertSame(
            [],
            $matches[0],
            sprintf(
                '%s writes a percent sign. Use the `percent` filter, or `percent_symbol()` where the '
                . 'sign stands on its own beside a field.',
                basename($path),
            ),
        );
    }

    /**
     * @dataProvider templates
     */
    public function testEveryConfirmationQuestionComesFromTheCatalogue(string $path): void
    {
        $matches = [];
        // A question is asked from `data-confirm` (assets/js/form-behaviours.js) since
        // Phase 41; a `confirm(` call is still caught if one comes back.
        preg_match_all('/(?:confirm\(\s*|data-confirm="\s*)([^)"]*)/', $this->source($path), $matches);

        $written = [];

        foreach ($matches[1] as $argument) {
            if (!str_contains($argument, 't(')) {
                $written[] = trim($argument);
            }
        }

        self::assertSame(
            [],
            $written,
            sprintf(
                '%s asks a question of its own. A question a reader is asked is a string like any '
                . 'other, and belongs in the catalogue.',
                basename($path),
            ),
        );
    }

    /**
     * @dataProvider templates
     */
    public function testEveryTableCanScrollInsteadOfWideningThePage(string $path): void
    {
        $source = $this->source($path);
        $offset = 0;
        $unwrapped = [];

        while (($position = strpos($source, '<table', $offset)) !== false) {
            $preceding = substr($source, 0, $position);

            // The wrapper is an ancestor, so it is enough that one was opened
            // before this point: `.chart-figures` is the dashboard chart's own
            // scrolling wrapper and counts as one.
            $wrappers = substr_count($preceding, 'table-scroll')
                + substr_count($preceding, 'table-wrap')
                + substr_count($preceding, 'chart-figures');

            if ($wrappers === 0) {
                $unwrapped[] = substr_count($preceding, "\n") + 1;
            }

            $offset = $position + 6;
        }

        self::assertSame(
            [],
            $unwrapped,
            sprintf(
                '%s has a table with no scrolling wrapper, on the line(s) named. A table cannot be laid '
                . 'out narrower than its content, so on a phone this one takes the whole page sideways '
                . 'with it.',
                basename($path),
            ),
        );
    }

    /**
     * @dataProvider templates
     */
    public function testAtMostOneButtonInATemplateIsFilled(string $path): void
    {
        self::assertLessThanOrEqual(
            1,
            substr_count($this->source($path), 'button-primary'),
            sprintf(
                '%s has more than one filled button. The accent means "the action here", and two of '
                . 'them means neither does.',
                basename($path),
            ),
        );
    }

    /**
     * Two requests into one announced region would overlap (K138): the older
     * must be cancelled so the newest result is the one a screen reader hears.
     */
    public function testEveryTriggerOfAnAnnouncedRegionSyncsOnIt(): void
    {
        $unsynced = [];
        $triggers = 0;

        foreach (self::templates() as $name => [$path]) {
            preg_match_all(
                '/hx-target="#(subscription-list|calendar-month|spend-trend|analytics-price-history)"([^>]*)/s',
                $this->source($path),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $triggers++;
                if (!str_contains($match[0], sprintf('hx-sync="#%s:replace"', $match[1]))) {
                    $unsynced[] = $name . ' (#' . $match[1] . ')';
                }
            }
        }

        self::assertGreaterThan(0, $triggers);
        self::assertSame([], $unsynced, 'Triggers that target an announced region must set hx-sync="#region:replace".');
    }

    /** The template as written, comments removed. */
    private function source(string $path): string
    {
        $source = (string) file_get_contents($path);

        // A Twig comment is not markup and is where a phase's reasoning is
        // written down, which is exactly where a "$540/year" example belongs.
        return (string) preg_replace('/\{#.*?#\}/s', '', $source);
    }

    /**
     * What is left of a template once everything that is not literal markup is
     * removed: no comments, no Twig expressions or tags, no inline script, and
     * no attribute whose value is a length or a coordinate.
     *
     * Newlines are preserved through each removal so that a failure can still
     * be traced back to a line by eye.
     */
    private function markup(string $path): string
    {
        $source = $this->source($path);

        $keepLines = static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n"));

        $source = (string) preg_replace_callback('/<script\b.*?<\/script>/s', $keepLines, $source);
        $source = (string) preg_replace_callback('/\{\{.*?\}\}/s', $keepLines, $source);
        $source = (string) preg_replace_callback('/\{%.*?%\}/s', $keepLines, $source);

        $attributes = implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            self::GEOMETRY_ATTRIBUTES,
        ));

        return (string) preg_replace_callback(
            sprintf('/\b(?:%s)="[^"]*"/s', $attributes),
            $keepLines,
            $source,
        );
    }
}
