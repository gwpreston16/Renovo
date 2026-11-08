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

        // The fakes find their files through $FAKES, the shell's own name for
        // this directory: a Windows path written into a script would have its
        // backslashes read as escapes, and its drive colon split PATH.
        file_put_contents($this->dir . '/clock', "1000000\n" . (1000000 + $seconds) . "\n");
        $this->fake('php', 'exit 0');
        $this->fake(
            'date',
            'c="$FAKES/clock"; line=$(head -n 1 "$c"); tail -n +2 "$c" > "$c.next"; mv "$c.next" "$c"; echo "$line"',
        );
        $this->fake('sleep', 'echo "$1" > "$FAKES/slept"; kill $PPID');
        file_put_contents($this->dir . '/loop.sh', $loop);

        // An argument list, not a command line, so no cmd.exe on Windows. One
        // shell more than needed, so the "Terminated" notice for the killed
        // loop lands in the null device with the rest of its output.
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            ['sh', '-c', 'FAKES=$(pwd); export FAKES; PATH="$FAKES:$PATH" sh ./loop.sh; true'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            $this->dir,
        );
        self::assertIsResource($process, 'sh is on the PATH');
        proc_close($process);
        self::assertFileExists($this->dir . '/slept', 'the loop reached its sleep');

        return trim((string) file_get_contents($this->dir . '/slept'));
    }

    private function fake(string $name, string $body): void
    {
        file_put_contents($this->dir . '/' . $name, "#!/bin/sh\n" . $body . "\n");
        chmod($this->dir . '/' . $name, 0o755);
    }
}
