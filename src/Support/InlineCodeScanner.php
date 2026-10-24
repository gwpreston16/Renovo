<?php

declare(strict_types=1);

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The Content-Security-Policy's rules, read off the templates.
 *
 * The policy (App\Security\ContentSecurityPolicy) runs no inline script, no
 * inline style and no `eval`. A template that breaks one of those still
 * renders: the browser just refuses the part that breaks it, so a confirm
 * stops asking or a bar stops having a width, and nothing fails until
 * somebody notices. This finds them in the source instead, and
 * InlineCodeScannerTest fails CI on any it finds:
 *
 *   - an inline `<script>` without a nonce. A `src=` script is served, and a
 *     `type="application/json"` one is data and never runs;
 *   - a `<style>` element or a `style=` attribute — geometry goes through a
 *     `data-css-*` attribute (assets/js/geometry.js);
 *   - an `on*=` event handler attribute — see assets/js/form-behaviours.js;
 *   - a `javascript:` URL;
 *   - an htmx feature that needs `eval`, which the layout's htmx-config turns
 *     off: an event filter in `hx-trigger` (`[…]`), a `js:` value in
 *     `hx-vals` or `hx-vars`, or `hx-on`.
 *
 * Twig comments are blanked first, so a comment can talk about a `<script>`.
 * Same shape as ExternalAssetScanner, for the same reason: a rule review
 * forgets is checked instead.
 */
final class InlineCodeScanner
{
    public const DIRECTORY = 'templates';

    /** @var array<string, string> Pattern => what it found. */
    private const RULES = [
        '~<script\b(?![^>]*\bsrc\s*=)(?![^>]*\bnonce\s*=)(?![^>]*\btype\s*=\s*["\']application/json["\'])[^>]*>~i'
            => 'an inline <script> without a nonce',
        '~<style\b~i' => 'a <style> element',
        '~<[a-z][a-z0-9-]*\b[^>]*?\sstyle\s*=~i' => 'a style= attribute',
        '~<[a-z][a-z0-9-]*\b[^>]*?\son[a-z]+\s*=~i' => 'an on*= event handler',
        '~javascript\s*:~i' => 'a javascript: URL',
        '~\bhx-trigger\s*=\s*(["\'])[^"\']*\[~i' => 'an event filter in hx-trigger, which needs eval',
        '~\bhx-va(?:ls|rs)\s*=\s*(["\'])\s*js:~i' => 'a js: value in hx-vals, which needs eval',
        '~\b(?:data-)?hx-on\b~i' => 'hx-on, which needs eval',
    ];

    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * Every finding, in a stable order.
     *
     * @return list<InlineCodeFinding>
     */
    public function scan(): array
    {
        $found = [];

        foreach ($this->templates() as $relative => $absolute) {
            $contents = file_get_contents($absolute);
            if ($contents === false) {
                continue;
            }

            foreach ($this->findingsIn($relative, $this->withoutComments($contents)) as $finding) {
                $found[] = $finding;
            }
        }

        return $found;
    }

    /**
     * @return list<InlineCodeFinding>
     */
    private function findingsIn(string $relative, string $source): array
    {
        $found = [];

        foreach (self::RULES as $pattern => $rule) {
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as [$text, $offset]) {
                // The line the offending part is on, not where its tag opens:
                // a multi-line tag's attribute is several lines below the `<`.
                $end = $offset + strlen($text);
                $found[] = new InlineCodeFinding($relative, substr_count($source, "\n", 0, $end) + 1, $rule);
            }
        }

        usort($found, static fn (InlineCodeFinding $a, InlineCodeFinding $b): int => $a->line <=> $b->line);

        return $found;
    }

    /**
     * The source with every `{# … #}` blanked, newlines kept, so line numbers
     * still match the file.
     */
    private function withoutComments(string $source): string
    {
        return (string) preg_replace_callback(
            '~\{#.*?#\}~s',
            static fn (array $match): string => (string) preg_replace('~[^\n]~', ' ', $match[0]),
            $source,
        );
    }

    /**
     * @return array<string, string> Relative path => absolute path, sorted.
     */
    private function templates(): array
    {
        $base = $this->rootPath . '/' . self::DIRECTORY;
        if (!is_dir($base)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'twig') {
                continue;
            }

            $relative = self::DIRECTORY . '/' . ltrim(
                str_replace('\\', '/', substr($file->getPathname(), strlen($base))),
                '/',
            );
            $files[$relative] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }
}
