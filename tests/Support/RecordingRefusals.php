<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\RefusalRecorder;

/**
 * Keeps the outbound refusals it is told about, for a test to read back.
 */
final class RecordingRefusals implements RefusalRecorder
{
    /** @var list<array{string, string}> Host and reason, in order. */
    public array $refusals = [];

    public function refused(string $host, string $reason): void
    {
        $this->refusals[] = [$host, $reason];
    }
}
