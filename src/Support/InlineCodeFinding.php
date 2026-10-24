<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One piece of inline script, style or eval found in a template.
 */
final class InlineCodeFinding
{
    /**
     * @param string $file Path relative to the repository root.
     * @param int    $line 1-based line number.
     * @param string $rule What was found, in words.
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $rule,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s:%d  %s', $this->file, $this->line, $this->rule);
    }
}
