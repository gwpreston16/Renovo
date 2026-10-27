<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `bin/dev-setup.sh` runs on Linux and Git Bash as well as macOS.
 *
 * GNU `mktemp` refuses a template with fewer than three trailing `X`s, so
 * `mktemp -t name` stopped the script at step 3 off a Mac.
 */
final class DevSetupScriptTest extends TestCase
{
    public function testEveryMktempTemplateEndsInXs(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/dev-setup.sh');

        self::assertSame([], $this->badTemplates($script));
        self::assertGreaterThanOrEqual(3, substr_count($script, 'mktemp '));
    }

    public function testATemplateWithoutXsIsReported(): void
    {
        self::assertSame(
            ['mktemp -t renovo-build'],
            $this->badTemplates('a="$(mktemp -t renovo-build)"; b="$(mktemp "${TMPDIR:-/tmp}/ok.XXXXXX")"'),
        );
    }

    /**
     * @return list<string> Each `mktemp` call whose template lacks six trailing `X`s.
     */
    private function badTemplates(string $script): array
    {
        preg_match_all('/mktemp\b[^)]*/', $script, $calls);
        $bad = [];
        foreach ($calls[0] as $call) {
            $call = trim($call);
            if (!preg_match('/X{6}"?$/', $call)) {
                $bad[] = $call;
            }
        }

        return $bad;
    }
}
