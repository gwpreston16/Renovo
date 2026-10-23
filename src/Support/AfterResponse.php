<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Work that runs once the response has gone to the browser (Phase 39): Back
 * up now's uploads, which can take far longer than the web server waits.
 */
interface AfterResponse
{
    public function defer(Closure $work): void;
}
