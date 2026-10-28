<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every way into the application runs in UTC, whatever PHP's `date.timezone`
 * is (Phase 64, K68).
 *
 * The pin is one call, {@see \App\Support\ProcessTimeZone::pin()}, made by each
 * entry point. The first test starts PHP in another zone and loads the files
 * that make the call; the second fails when an entry point is added that
 * neither loads the bootstrap nor pins the zone itself, so the pin cannot be
 * forgotten the way a `WHERE` clause can.
 */
final class EntryPointTimeZoneTest extends TestCase
{
    /**
     * Scripts that are not a way into the application: they read source files
     * and never a date, so the zone they run in does not matter.
     */
    private const NOT_ENTRY_POINTS = [
        // Scans src/ for date-parsing sites (Phase 63); parses no date.
        'bin/time-zone-sites',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pinnedFiles(): array
    {
        $cases = [];
        foreach (['config/bootstrap.php', 'phinx.php'] as $file) {
            foreach (['America/Los_Angeles', 'Pacific/Auckland'] as $zone) {
                $cases[$file . ' in ' . $zone] = [$file, $zone];
            }
        }

        return $cases;
    }

    #[DataProvider('pinnedFiles')]
    public function testLoadingTheFileLeavesPhpInUtc(string $file, string $zone): void
    {
        $code = sprintf(
            'require %s; $before = date_default_timezone_get(); require %s;'
            . ' echo $before, " ", date_default_timezone_get();',
            var_export($this->root . '/vendor/autoload.php', true),
            var_export($this->root . '/' . $file, true),
        );

        $process = proc_open(
            [PHP_BINARY, '-d', 'date.timezone=' . $zone, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, $err);
        self::assertSame($zone . ' UTC', trim($out), 'PHP started in ' . $zone . ' and ' . $file . ' pinned it');
    }

    /**
     * The real entry points, run as PHP would run them, in a zone ahead of UTC.
     * A probe prepended to the script reports, as the process ends, the zone
     * PHP started in and the one it ended in, so a pin that is never reached
     * fails here though its text is present.
     *
     * @return array<string, array{list<string>, array<string, string>}>
     */
    public static function runnableEntryPoints(): array
    {
        return [
            'bin/console' => [['bin/console'], []],
            'public/index.php' => [['public/index.php'], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/health']],
        ];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $server
     */
    #[DataProvider('runnableEntryPoints')]
    public function testRunningTheEntryPointEndsInUtc(array $arguments, array $server): void
    {
        $probe = tempnam(sys_get_temp_dir(), 'tz-probe');
        self::assertIsString($probe);
        file_put_contents($probe, '<?php register_shutdown_function(static function (): void {'
            . ' fwrite(STDERR, "\nZONE " . ini_get("date.timezone") . " " . date_default_timezone_get() . "\n"); });');

        try {
            $process = proc_open(
                [
                    PHP_BINARY,
                    '-d',
                    'date.timezone=Pacific/Auckland',
                    '-d',
                    'auto_prepend_file=' . $probe,
                    ...$arguments,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->root,
                [...getenv(), ...$server],
            );
            self::assertIsResource($process);

            stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            unlink($probe);
        }

        self::assertMatchesRegularExpression('/^ZONE (\S+) (\S+)$/m', $err, $err);
        preg_match('/^ZONE (\S+) (\S+)$/m', $err, $zones);
        self::assertSame('Pacific/Auckland', $zones[1], 'PHP started in Auckland');
        self::assertSame('UTC', $zones[2], $arguments[0] . ' ended in UTC');
    }

    public function testEveryEntryPointPinsTheZone(): void
    {
        $bootstrap = (string) file_get_contents($this->root . '/config/bootstrap.php');
        self::assertStringContainsString('ProcessTimeZone::pin();', $bootstrap);

        $entryPoints = $this->entryPoints();
        self::assertContains('public/index.php', $entryPoints, 'the scan finds the front controller');
        self::assertContains('bin/console', $entryPoints, 'and the console');

        foreach ($entryPoints as $file) {
            $source = self::code((string) file_get_contents($this->root . '/' . $file));
            self::assertMatchesRegularExpression(
                '~^\s*(\$\w+\s*=\s*)?require(_once)?\s[^;]*/config/bootstrap\.php\'\s*;'
                . '|^\s*[\w\\\\]*ProcessTimeZone::pin\(\);~m',
                $source,
                $file . ' neither loads config/bootstrap.php nor calls ProcessTimeZone::pin(), so it runs'
                . ' in whatever zone PHP started in (K68)',
            );
        }
    }

    /**
     * The pin holds only if nothing moves the zone again after start-up, so
     * the one place that may set it is the pin: not src/, not the container
     * or settings every request builds, not an entry point after its pin, and
     * not a migration or seed.
     */
    public function testNothingSetsTheZoneButThePin(): void
    {
        $offenders = [];
        foreach ($this->appSources() as $file) {
            if ($file === 'src/Support/ProcessTimeZone.php') {
                continue;
            }
            $source = self::code((string) file_get_contents($this->root . '/' . $file));
            if (preg_match('/date_default_timezone_set\s*\(|ini_set\s*\(\s*[\'"]date\.timezone/', $source) === 1) {
                $offenders[] = $file;
            }
        }

        self::assertContains('config/container.php', $this->appSources(), 'the scan reaches config/');
        self::assertSame([], $offenders, 'only ProcessTimeZone may set PHP\'s zone (K68)');
    }

    /**
     * Every PHP file the application runs: src/, config/, migrations/,
     * seeds/ and the entry points.
     *
     * @return list<string>
     */
    private function appSources(): array
    {
        $files = $this->entryPoints();
        foreach (['src', 'config', 'migrations', 'seeds'] as $directory) {
            if (!is_dir($this->root . '/' . $directory)) {
                continue;
            }
            $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/' . $directory));
            foreach ($paths as $path) {
                if ($path->isFile() && $path->getExtension() === 'php') {
                    $files[] = substr($path->getPathname(), strlen($this->root) + 1);
                }
            }
        }

        return $files;
    }

    /**
     * The source with its comments removed, so a mention in a comment neither
     * satisfies nor trips a check.
     */
    private static function code(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * The web root's PHP files, the PHP scripts in bin/, Phinx's configuration
     * and the test bootstrap.
     *
     * @return list<string>
     */
    private function entryPoints(): array
    {
        $files = ['phinx.php', 'tests/bootstrap.php'];

        foreach (glob($this->root . '/public/*.php') ?: [] as $path) {
            $files[] = 'public/' . basename($path);
        }

        foreach (glob($this->root . '/bin/*') ?: [] as $path) {
            $file = 'bin/' . basename($path);
            if (is_file($path) && !in_array($file, self::NOT_ENTRY_POINTS, true) && self::isPhpScript($path)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private static function isPhpScript(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 64);

        return str_starts_with($head, '<?php') || preg_match('/\A#![^\n]*php/', $head) === 1;
    }
}
