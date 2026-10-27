<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Renovo has kept a payment ledger since Phase 34. A comment that says it does
 * not describes behaviour the code no longer has.
 */
final class StaleLedgerCommentsTest extends TestCase
{
    private const STALE = ['keeps no ledger', 'no payment ledger', 'no ledger to page back into'];

    public function testNoCommentSaysThereIsNoLedger(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        foreach (['src', 'templates'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }
                $text = strtolower((string) preg_replace('/[\s*#]+/', ' ', (string) file_get_contents($file->getPathname())));
                foreach (self::STALE as $phrase) {
                    if (str_contains($text, $phrase)) {
                        $found[] = substr($file->getPathname(), strlen($root) + 1) . ": \"$phrase\"";
                    }
                }
            }
        }

        self::assertSame([], $found, "Comments that say there is no ledger:\n" . implode("\n", $found));
    }
}
