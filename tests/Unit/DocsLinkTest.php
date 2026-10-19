<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\MarkdownLinkChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The README, CHANGELOG, ROADMAP and every page under `docs/` link only to
 * files, headings and images that exist.
 *
 * The documentation is spread over many pages that point at each other's
 * sections, and a renamed heading breaks a link silently. This test is
 * offline: `http(s)://` links are not fetched. `docs/phases/` is not read,
 * because archived briefs keep the paths they had when they were written.
 */
final class DocsLinkTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/docs-links';

    public function testEveryLinkAnchorAndImageInTheDocumentationResolves(): void
    {
        $root = dirname(__DIR__, 2);
        $problems = (new MarkdownLinkChecker($root))->check($this->documentationFiles($root));

        self::assertSame([], $problems, "Broken documentation links:\n" . implode("\n", $problems));
    }

    public function testAWellFormedFixturePasses(): void
    {
        $checker = new MarkdownLinkChecker(self::FIXTURES);

        self::assertSame([], $checker->check(['good.md', 'sub/page.md']));
    }

    public function testEachKindOfBreakageIsReported(): void
    {
        $problems = (new MarkdownLinkChecker(self::FIXTURES))->check(['bad.md']);

        self::assertSame([
            'bad.md:3: link target "nowhere.md" does not exist',
            'bad.md:4: anchor "#not-here" matches no heading in this file',
            'bad.md:5: anchor "#nope" matches no heading in sub/page.md',
            'bad.md:6: image "present.png" has no alt text',
            'bad.md:7: image "absent.png" does not exist',
            'bad.md:8: anchor "#nor-here" matches no heading in this file',
        ], $problems);
    }

    public function testHeadingsAreSluggedAsGitHubSlugsThem(): void
    {
        self::assertSame(
            'running-against-mysql--mariadb',
            MarkdownLinkChecker::slug('Running against MySQL / MariaDB'),
        );
        self::assertSame('the-ledgerbackfill-command', MarkdownLinkChecker::slug('The `ledger:backfill` command'));
        self::assertSame('whats-new', MarkdownLinkChecker::slug("What's new"));
        self::assertSame('v1-phases-129', MarkdownLinkChecker::slug('v1 (Phases 1–29)'));
        self::assertSame('bold-and-a-link', MarkdownLinkChecker::slug('**Bold** and [a link](x.md)'));
        self::assertSame('app_url-and-db_name', MarkdownLinkChecker::slug('APP_URL and DB_NAME'));
        self::assertSame('a--b', MarkdownLinkChecker::slug('A — b'));
    }

    public function testDuplicateHeadingsAreNumberedAndFencedCodeIsIgnored(): void
    {
        $markdown = "# Shortcuts\n\n```bash\n# Shortcuts\n```\n\n## Shortcuts\n\n"
            . "### Shortcuts\n\n<a id=\"kept\"></a>\n";

        self::assertSame(
            ['shortcuts', 'shortcuts-1', 'shortcuts-2', 'kept'],
            MarkdownLinkChecker::anchorsIn($markdown),
        );
    }

    /**
     * @return list<string>
     */
    private function documentationFiles(string $root): array
    {
        $files = ['README.md', 'CHANGELOG.md', 'ROADMAP.md'];
        $pages = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs'));
        foreach ($pages as $page) {
            $relative = substr($page->getPathname(), strlen($root) + 1);
            if ($page->isFile() && str_ends_with($relative, '.md') && !str_starts_with($relative, 'docs/phases/')) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }
}
