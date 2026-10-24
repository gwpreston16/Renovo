<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\InlineCodeFinding;
use App\Support\InlineCodeScanner;
use PHPUnit\Framework\TestCase;

/**
 * The template guard for the Content-Security-Policy (Phase 41).
 *
 * Two halves, like ExternalAssetScannerTest: that the scanner finds each thing
 * the policy refuses, on templates written here; and that this repository's
 * templates have none, which is the assertion CI makes.
 */
final class InlineCodeScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/renovo-inline-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/templates/partials', 0o775, true);
    }

    protected function tearDown(): void
    {
        $files = [
            ...(array) glob($this->root . '/templates/*.twig'),
            ...(array) glob($this->root . '/templates/partials/*.twig'),
        ];
        foreach ($files as $file) {
            unlink((string) $file);
        }
        rmdir($this->root . '/templates/partials');
        rmdir($this->root . '/templates');
        rmdir($this->root);
    }

    public function testTheTemplatesHaveNoInlineScriptStyleOrEval(): void
    {
        $found = (new InlineCodeScanner(dirname(__DIR__, 2)))->scan();

        self::assertSame(
            [],
            array_map(static fn (InlineCodeFinding $finding): string => $finding->describe(), $found),
            'The Content-Security-Policy refuses these. Move script into a served file, '
            . 'a handler into a data attribute (assets/js/form-behaviours.js) and a size or colour '
            . 'into a data-css-* attribute (assets/js/geometry.js).',
        );
    }

    public function testFindsAnInlineScriptWithoutANonce(): void
    {
        $this->write('page.twig', "<p>Fine</p>\n<script>\n    alert(1);\n</script>\n");

        self::assertSame(['templates/page.twig:2  an inline <script> without a nonce'], $this->scan());
    }

    public function testAllowsAServedScriptANoncedOneAndJsonData(): void
    {
        $this->write('page.twig', <<<'TWIG'
            <script src="{{ asset('/assets/app.js') }}" defer></script>
            <script nonce="{{ csp_nonce() }}">window.x = 1;</script>
            <script id="messages" type="application/json">{"a": 1}</script>
            TWIG);

        self::assertSame([], $this->scan());
    }

    public function testFindsAnEventHandlerAttributeOnAMultiLineTag(): void
    {
        $this->write('partials/form.twig', <<<'TWIG'
            <form method="post"
                  action="/delete"
                  onsubmit="return confirm('Sure?')">
            </form>
            <button type="button" onclick="go()">Go</button>
            TWIG);

        self::assertSame([
            'templates/partials/form.twig:3  an on*= event handler',
            'templates/partials/form.twig:5  an on*= event handler',
        ], $this->scan());
    }

    public function testFindsAStyleAttributeAndAStyleElement(): void
    {
        $this->write('page.twig', <<<'TWIG'
            <span class="bar" style="width: {{ width }}"></span>
            <style>.bar { color: red; }</style>
            <span class="bar" data-css-width="{{ width }}"></span>
            TWIG);

        self::assertSame([
            'templates/page.twig:1  a style= attribute',
            'templates/page.twig:2  a <style> element',
        ], $this->scan());
    }

    public function testFindsAJavascriptUrl(): void
    {
        $this->write('page.twig', '<a href="javascript:void(0)">Nothing</a>');

        self::assertSame(['templates/page.twig:1  a javascript: URL'], $this->scan());
    }

    public function testFindsTheHtmxFeaturesThatNeedEval(): void
    {
        $this->write('page.twig', <<<'TWIG'
            <form hx-get="/x" hx-trigger="input[target.type=='text'] changed delay:500ms"></form>
            <button hx-post="/x" hx-vals="js:{a: 1}"></button>
            <button hx-on:click="go()"></button>
            <form hx-get="/x" hx-trigger="change, renovo:typed delay:500ms"></form>
            TWIG);

        self::assertSame([
            'templates/page.twig:1  an event filter in hx-trigger, which needs eval',
            'templates/page.twig:2  a js: value in hx-vals, which needs eval',
            'templates/page.twig:3  hx-on, which needs eval',
        ], $this->scan());
    }

    public function testATwigCommentMayTalkAboutAScript(): void
    {
        $this->write('page.twig', <<<'TWIG'
            {#
                This used to be an inline <script> with onclick="x()" and a
                style="width: 1px" — all gone.
            #}
            <p>Fine</p>
            <p style="x">Not fine</p>
            TWIG);

        self::assertSame(['templates/page.twig:6  a style= attribute'], $this->scan());
    }

    /**
     * @return list<string>
     */
    private function scan(): array
    {
        return array_map(
            static fn (InlineCodeFinding $finding): string => $finding->describe(),
            (new InlineCodeScanner($this->root))->scan(),
        );
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->root . '/templates/' . $relative, $contents);
    }
}
