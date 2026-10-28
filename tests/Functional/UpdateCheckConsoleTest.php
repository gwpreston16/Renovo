<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\InstanceSettingsRepository;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\TestKeys;

/**
 * The update check as the scheduler and an operator run it (Phase 37): a real
 * `php bin/console` process, its exit code and what it says. Nothing here
 * reaches GitHub: with checks off nothing is asked, and the failing run goes
 * through a proxy nothing listens on.
 */
final class UpdateCheckConsoleTest extends DatabaseTestCase
{
    /** A port nothing listens on, so a check that is attempted fails fast. */
    private const DEAD_PROXY = 'http://127.0.0.1:1';

    public function testDisabledPrintsTheMessageAndAsksNothing(): void
    {
        $this->settings()->setUpdateCheckEnabled(false);

        [$code, $out, $err] = $this->console(['updates:check']);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Update checks are disabled.', $out);
        self::assertNull($this->settings()->updateLastAttemptAt(), 'No attempt was made.');

        // The environment turns it off whatever the setting says.
        $this->settings()->setUpdateCheckEnabled(true);
        [$code, $out] = $this->console(['updates:check'], ['UPDATE_CHECK' => 'false']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Update checks are disabled.', $out);
        self::assertNull($this->settings()->updateLastAttemptAt());
    }

    public function testFailureExitsOne(): void
    {
        $this->settings()->setUpdateCheckEnabled(true);

        [$code, $out, $err] = $this->console(['updates:check'], ['HTTPS_PROXY' => self::DEAD_PROXY]);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('The update check failed; see the log.', $err);
        self::assertNotNull($this->settings()->updateLastAttemptAt(), 'The attempt was recorded.');
    }

    public function testTheSchedulersUpdateStepNeverFailsTheReminderRun(): void
    {
        // Off: the run finishes, and asks GitHub nothing.
        $this->settings()->setUpdateCheckEnabled(false);
        [$code, $out, $err] = $this->console(['reminders:run']);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Checked 0 user(s)', $out);
        self::assertNull($this->settings()->updateLastAttemptAt());

        // On, and GitHub unreachable: the step logs and swallows it.
        $this->settings()->setUpdateCheckEnabled(true);
        [$code, $out, $err] = $this->console(['reminders:run'], ['HTTPS_PROXY' => self::DEAD_PROXY]);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Checked 0 user(s)', $out);
        self::assertNotNull($this->settings()->updateLastAttemptAt(), 'The step did try.');
    }

    private function settings(): InstanceSettingsService
    {
        return new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $extra
     * @return array{0: int, 1: string, 2: string}
     */
    private function console(array $arguments, array $extra = []): array
    {
        $environment = [
            ...getenv(),
            'DB_DRIVER' => $this->env('DB_DRIVER', 'pgsql'),
            'DB_HOST' => $this->env('DB_HOST', '127.0.0.1'),
            'DB_PORT' => $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'DB_NAME' => $this->env('DB_NAME', 'renovo'),
            'DB_USER' => $this->env('DB_USER', 'renovo'),
            'DB_PASSWORD' => $this->env('DB_PASSWORD', 'renovo'),
            ...$extra,
        ];

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/console', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
