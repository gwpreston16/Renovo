<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The top bar on a phone (K157) and a disabled button (K158), read off the
 * stylesheet and the templates as authored.
 *
 * The browser check in the phase brief is what shows the title keeps its
 * room; these pin the rules that give it that room, so a later edit that
 * undoes one fails here rather than on somebody's phone.
 */
final class TopBarAndButtonStatesTest extends TestCase
{
    /** Every variant whose hover lights a button up. */
    private const VARIANT_HOVERS = [
        '.button:hover',
        '.button-primary:hover',
        '.button.topbar-add:hover',
        '.button-danger:hover',
        '.button-outline:hover',
    ];

    private string $css;

    protected function setUp(): void
    {
        $this->css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/components.css');
    }

    /**
     * Every template that fills the top bar's page actions, found rather than
     * listed, so a new one is held to the same rule.
     *
     * @return iterable<string, array{string}>
     */
    public static function pageActionTemplates(): iterable
    {
        $root = dirname(__DIR__, 2) . '/templates/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, '{% block page_actions %}')) {
                $template = substr($file->getPathname(), strlen($root));
                yield $template => [$template];
            }
        }
    }

    /**
     * Below 1440px the rule hides `.button-label`, leaving the icon; the
     * label stays for screen readers and the title for hover.
     *
     * @dataProvider pageActionTemplates
     */
    public function testEveryTopBarActionHasAnIconAndALabel(string $template): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
        self::assertSame(1, preg_match('~\{% block page_actions %\}(.*?)\{% endblock %\}~s', $source, $block));

        // Any link or button wearing `.button`; Calendar's checkbox is not one.
        $pattern = '~<(a|button)\b[^>]*class="(?:[^"]*\s)?button(?:\s[^"]*)?"[^>]*>.*?</\1>~s';
        preg_match_all($pattern, $block[1], $actions);

        foreach ($actions[0] as $action) {
            self::assertMatchesRegularExpression("~\{\{-? icon\('[a-z-]+'\)~", $action, $template);
            self::assertStringContainsString('<span class="button-label">', $action, $template);
            self::assertStringContainsString('title="', $action, $template);
        }
    }

    public function testBelow1440ATopBarActionShowsOnlyItsIcon(): void
    {
        $phone = $this->mediaBlock('(max-width: 1439px)');

        self::assertStringContainsString('.topbar-page-actions .button-label {', $phone);
        self::assertStringContainsString('clip: rect(0 0 0 0);', $phone);
    }

    /**
     * On `.topbar-add` alone both rules lost to `.button`'s `display`, so Add
     * new showed on a phone and squeezed the title.
     */
    public function testAddNewIsHiddenBelow768AndShownFrom768(): void
    {
        $hide = strpos($this->css, ".button.topbar-add {\n    display: none;\n}");
        self::assertNotFalse($hide, 'Add new is not hidden by default.');

        $tablet = $this->mediaBlock('(min-width: 768px)', $showAt);
        self::assertMatchesRegularExpression('~\.button\.topbar-add \{\s*display: inline-flex;~', $tablet);
        self::assertGreaterThan($hide, $showAt, 'The show must come after the hide.');

        self::assertDoesNotMatchRegularExpression(
            '~(?<![.\w])\.topbar-add \{[^}]*display:~',
            $this->css,
            'A display on .topbar-add alone loses to .button.',
        );
    }

    public function testADisabledButtonLooksDisabledAndDoesNotLightUp(): void
    {
        $selectors = ".button:disabled,\n.button:disabled:hover,\n.button[aria-disabled='true'],\n"
            . ".button[aria-disabled='true']:hover {";
        $at = strpos($this->css, $selectors);
        self::assertNotFalse($at, 'No disabled rule for .button.');

        $body = substr($this->css, $at, (int) strpos($this->css, '}', $at) - $at);
        self::assertStringContainsString('color: var(--faint);', $body);
        self::assertStringContainsString('cursor: not-allowed;', $body);
        self::assertStringContainsString('background: var(--surface-2);', $body);
        self::assertStringContainsString('border-color: transparent;', $body);

        // Same specificity as `.button.topbar-add:hover`, so it has to come
        // after every variant's hover to win it.
        foreach (self::VARIANT_HOVERS as $hover) {
            $hoverAt = strpos($this->css, "\n" . $hover);
            self::assertNotFalse($hoverAt, $hover);
            self::assertGreaterThan($hoverAt, $at, 'The disabled rule must follow ' . $hover);
        }
    }

    /**
     * Found while checking K157: Forecast's "If I cancelled" rows widened the
     * page past a phone. The link takes its own line, and the list's grid
     * column may be narrower than a row.
     */
    public function testTheForecastRowsFitAPhone(): void
    {
        $screens = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/screens.css');
        $start = strpos($screens, "@media (max-width: 599px) {\n    .forecast-cancel .top-list {");
        self::assertNotFalse($start, 'No phone rule for the forecast rows.');
        $block = substr($screens, $start, 400);

        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $block);
        self::assertMatchesRegularExpression('~\.forecast-cancel \.top-row \{\s*flex-wrap: wrap;~', $block);
        self::assertMatchesRegularExpression(
            '~\.forecast-cancel \.forecast-scenario-link \{\s*flex-basis: 100%;~',
            $block,
        );
    }

    /**
     * The body of the first top-level `@media <query>` block that contains a
     * rule, found by walking braces.
     */
    private function mediaBlock(string $query, ?int &$offset = null): string
    {
        $needle = '@media ' . $query . ' {';
        $search = 0;

        while (($start = strpos($this->css, $needle, $search)) !== false) {
            $open = $start + strlen($needle);
            $depth = 1;
            $i = $open;
            while ($i < strlen($this->css) && $depth > 0) {
                $depth += match ($this->css[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                $i++;
            }

            $body = substr($this->css, $open, $i - $open - 1);
            if (str_contains($body, '.topbar') || str_contains($body, 'topbar-add')) {
                $offset = $start;

                return $body;
            }

            $search = $i;
        }

        self::fail('No ' . $needle . ' block for the top bar.');
    }
}
