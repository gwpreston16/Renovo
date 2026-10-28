<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The scheduler's loop (K114): a pass starts about a day after the last one
 * started, so a slow pass shortens the sleep rather than pushing every later
 * pass back. The loop is the compose command itself, run under `sh` with a
 * fake `date` that moves the clock by the pass's length, and a `sleep` that
 * records its argument and stops the shell, since the loop never ends.
 */
final class ComposeSchedulerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/renovo-scheduler-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            unlink((string) $file);
        }
        rmdir($this->dir);
    }

    public function testTheSleepSubtractsThePassDuration(): void
    {
        self::assertSame('85000', $this->sleepAfterAPassOf(1400));
    }

    public function testAPassThatTakesNoTimeSleepsAFullDay(): void
    {
        self::assertSame('86400', $this->sleepAfterAPassOf(0));
    }

    public function testAPassLongerThanADayDoesNotSleep(): void
    {
        self::assertSame('0', $this->sleepAfterAPassOf(90000));
        self::assertSame('0', $this->sleepAfterAPassOf(86400));
    }

    /**
     * Runs the loop body once and returns what it passed to `sleep`.
     */
    private function sleepAfterAPassOf(int $seconds): string
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 2) . '/docker-compose.yml');
        $command = $compose['services']['scheduler']['command'];
        self::assertIsArray($command);
        // Compose reads `$$` as a literal `$`.
        $loop = str_replace('$$', '$', (string) $command[0]);

        file_put_contents($this->dir . '/clock', "1000000\n" . (1000000 + $seconds) . "\n");
        $this->fake('php', 'exit 0');
        $clock = $this->dir . '/clock';
        $this->fake('date', sprintf(
            'line=$(head -n 1 %1$s); tail -n +2 %1$s > %1$s.next; mv %1$s.next %1$s; echo "$line"',
            $clock,
        ));
        $this->fake('sleep', sprintf('echo "$1" > %s/slept; kill $PPID', $this->dir));

        $script = $this->dir . '/loop.sh';
        file_put_contents($script, $loop);
        // One shell more than needed, so the "Terminated" notice for the killed loop goes to /dev/null.
        $run = sprintf('PATH=%s:$PATH sh %s', escapeshellarg($this->dir), escapeshellarg($script));
        exec('sh -c ' . escapeshellarg($run . '; true') . ' >/dev/null 2>&1');
        self::assertFileExists($this->dir . '/slept', 'the loop reached its sleep');

        return trim((string) file_get_contents($this->dir . '/slept'));
    }

    private function fake(string $name, string $body): void
    {
        file_put_contents($this->dir . '/' . $name, "#!/bin/sh\n" . $body . "\n");
        chmod($this->dir . '/' . $name, 0o755);
    }
}
