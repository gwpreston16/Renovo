<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Finds the broken links and images in a set of Markdown files, offline.
 *
 * It reads what GitHub would render: links and images outside fenced code
 * blocks and code spans. A relative target must exist (a directory counts); a
 * `#anchor`, on its own or after a `.md` path, must match a heading in its
 * target slugged the way GitHub slugs them, or an explicit `<a id>`/`<a name>`;
 * an image must have alt text and a file behind it. `http(s)://` and `mailto:`
 * targets are never fetched.
 */
final class MarkdownLinkChecker
{
    /** @var array<string, list<string>> Anchors per file, cached by real path. */
    private array $anchors = [];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param list<string> $files Paths relative to the root.
     * @return list<string> One line per problem, `file:line: message`.
     */
    public function check(array $files): array
    {
        $problems = [];
        foreach ($files as $file) {
            foreach ($this->checkFile($file) as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * GitHub's heading slug: the rendered text, lower-cased, with everything
     * but letters, digits, spaces, hyphens and underscores dropped, and each
     * space turned into a hyphen. Duplicates are numbered by the caller.
     */
    public static function slug(string $heading): string
    {
        $text = self::renderedText($heading);
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} \-]/u', '', $text);

        return str_replace(' ', '-', $text);
    }

    /**
     * @return list<string> Every anchor a file offers, in document order, with
     *                      duplicate slugs numbered `-1`, `-2`, … as GitHub does.
     */
    public static function anchorsIn(string $markdown): array
    {
        $anchors = [];
        $seen = [];
        foreach (self::proseLines($markdown) as [$line]) {
            if (preg_match_all('/<a\s+(?:[^>]*\s)?(?:id|name)="([^"]+)"/i', $line, $m)) {
                foreach ($m[1] as $id) {
                    $anchors[] = $id;
                }
            }
            if (!preg_match('/^ {0,3}#{1,6}\s+(.*?)(?:\s+#+)?\s*$/', $line, $m)) {
                continue;
            }
            $slug = self::slug($m[1]);
            if (isset($seen[$slug])) {
                $numbered = $slug . '-' . $seen[$slug];
                $seen[$slug]++;
                $anchors[] = $numbered;
                continue;
            }
            $seen[$slug] = 1;
            $anchors[] = $slug;
        }

        return $anchors;
    }

    /**
     * @return list<string>
     */
    private function checkFile(string $file): array
    {
        $path = $this->root . '/' . $file;
        $markdown = (string) file_get_contents($path);
        $problems = [];

        // A link may wrap over several lines, so each paragraph is read whole
        // and a problem is reported on the line where its link starts.
        foreach (self::paragraphs($markdown) as [$text, $firstLine]) {
            $text = self::withoutCodeSpans($text);
            $lineAt = static fn (int $offset): int => $firstLine + substr_count($text, "\n", 0, $offset);

            foreach (self::links($text) as [$isImage, $alt, $target, $offset]) {
                $where = sprintf('%s:%d: ', $file, $lineAt($offset));
                if ($isImage && trim($alt) === '') {
                    $problems[] = $where . sprintf('image "%s" has no alt text', $target);
                }
                $problem = $this->checkTarget(dirname($path), $path, $target, $isImage);
                if ($problem !== null) {
                    $problems[] = $where . $problem;
                }
            }

            if (preg_match_all('/<img\b[^>]*>/i', $text, $tags, PREG_OFFSET_CAPTURE)) {
                foreach ($tags[0] as [$tag, $offset]) {
                    $where = sprintf('%s:%d: ', $file, $lineAt($offset));
                    $src = preg_match('/\ssrc="([^"]*)"/i', $tag, $m) ? $m[1] : '';
                    $alt = preg_match('/\salt="([^"]*)"/i', $tag, $m) ? $m[1] : '';
                    if (trim($alt) === '') {
                        $problems[] = $where . sprintf('image "%s" has no alt text', $src);
                    }
                    $problem = $this->checkTarget(dirname($path), $path, $src, true);
                    if ($problem !== null) {
                        $problems[] = $where . $problem;
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Runs of consecutive prose lines; a blank line, a heading or a fence
     * ends one.
     *
     * @return list<array{0: string, 1: int}> Each paragraph with its first line number.
     */
    private static function paragraphs(string $markdown): array
    {
        $paragraphs = [];
        $current = null;
        $previous = 0;
        foreach (self::proseLines($markdown) as [$line, $number]) {
            $breaks = trim($line) === '' || preg_match('/^ {0,3}#{1,6}\s/', $line) === 1;
            if ($current !== null && ($breaks || $number !== $previous + 1)) {
                $paragraphs[] = $current;
                $current = null;
            }
            if (!$breaks) {
                $current = $current === null ? [$line, $number] : [$current[0] . "\n" . $line, $current[1]];
            } elseif (trim($line) !== '') {
                $paragraphs[] = [$line, $number];
            }
            $previous = $number;
        }
        if ($current !== null) {
            $paragraphs[] = $current;
        }

        return $paragraphs;
    }

    private function checkTarget(string $dir, string $self, string $target, bool $isImage): ?string
    {
        if ($target === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $target)) {
            return null;
        }

        $hash = strpos($target, '#');
        $file = $hash === false ? $target : substr($target, 0, $hash);
        $anchor = $hash === false ? null : substr($target, $hash + 1);
        $file = rawurldecode(explode('?', $file, 2)[0]);

        $resolved = $file === '' ? $self : $dir . '/' . $file;
        if (!file_exists($resolved)) {
            return sprintf('%s "%s" does not exist', $isImage ? 'image' : 'link target', $target);
        }
        if ($anchor === null || $anchor === '' || is_dir($resolved) || !str_ends_with(strtolower($resolved), '.md')) {
            return null;
        }

        $anchor = rawurldecode($anchor);
        if (!in_array($anchor, $this->anchorsOf($resolved), true)) {
            return sprintf('anchor "#%s" matches no heading in %s', $anchor, $file === '' ? 'this file' : $file);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function anchorsOf(string $path): array
    {
        $key = (string) realpath($path);

        return $this->anchors[$key] ??= self::anchorsIn((string) file_get_contents($path));
    }

    /**
     * The lines GitHub renders as Markdown: everything outside fenced code.
     *
     * @return list<array{0: string, 1: int}> Each line with its 1-based number.
     */
    private static function proseLines(string $markdown): array
    {
        $lines = [];
        $fence = null;
        foreach (preg_split('/\R/', $markdown) ?: [] as $i => $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                if ($fence === null) {
                    $fence = $m[1];
                    continue;
                }
                if ($m[1][0] === $fence[0] && strlen($m[1]) >= strlen($fence)) {
                    $fence = null;
                    continue;
                }
            }
            if ($fence === null) {
                $lines[] = [$line, $i + 1];
            }
        }

        return $lines;
    }

    private static function withoutCodeSpans(string $line): string
    {
        return (string) preg_replace_callback(
            '/(`+)(?:(?!\1).)+?\1/s',
            static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
            $line,
        );
    }

    /**
     * Inline links and images, plus reference definitions (`[x]: target`).
     *
     * @return list<array{0: bool, 1: string, 2: string, 3: int}> [isImage, text, target, offset]
     */
    private static function links(string $line): array
    {
        $found = [];
        $pattern = '/(!?)\[((?:[^\[\]]|\[[^\[\]]*\])*)\]'
            . '\(\s*(<[^>]*>|[^\s()]*(?:\([^\s()]*\)[^\s()]*)*)(?:\s+"[^"]*")?\s*\)/';
        if (preg_match_all($pattern, $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $match) {
                $found[] = [$match[1][0] === '!', $match[2][0], trim($match[3][0], '<>'), $match[0][1]];
            }
        }
        if (preg_match_all('/^ {0,3}\[[^\]]+\]:\s*(\S+)/m', $line, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $match) {
                $found[] = [false, '', trim($match[1][0], '<>'), $match[0][1]];
            }
        }

        return $found;
    }

    /**
     * Heading source as GitHub renders it to text: code-span backticks,
     * emphasis markers and link syntax removed, HTML tags dropped.
     */
    private static function renderedText(string $heading): string
    {
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
        $text = (string) preg_replace('/<[^>]+>/', '', $text);
        $text = str_replace('`', '', $text);
        $text = (string) preg_replace('/(\*{1,3})(\S(?:.*?\S)?)\1/u', '$2', $text);
        // An underscore inside a word (snake_case) is not emphasis.
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])(_{1,3})(\S(?:.*?\S)?)\1(?![\p{L}\p{N}])/u', '$2', $text);

        return html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
