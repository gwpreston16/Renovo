<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\Environment;
use PHPUnit\Framework\TestCase;

/**
 * K5: no request header can become a setting. php-fpm hands a `Proxy:` header
 * to PHP as `HTTP_PROXY` in `$_SERVER` (and can in `$_ENV`); only the process
 * environment and `.env` may set an `HTTP_*` name.
 */
final class EnvironmentTest extends TestCase
{
    private const NAME = 'HTTP_RENOVO_TEST_PROXY';

    protected function tearDown(): void
    {
        unset($_SERVER[self::NAME], $_ENV[self::NAME], $_SERVER['RENOVO_TEST_VALUE'], $_ENV['RENOVO_TEST_VALUE']);
        putenv(self::NAME);
        putenv('RENOVO_TEST_VALUE');
    }

    public function testARequestHeaderInServerOrEnvIsNeverASetting(): void
    {
        $_SERVER[self::NAME] = 'http://attacker.example:8080';
        $_ENV[self::NAME] = 'http://attacker.example:8080';

        self::assertNull(Environment::get(self::NAME));
    }

    public function testTheProcessEnvironmentSetsAnHttpName(): void
    {
        $_SERVER[self::NAME] = 'http://attacker.example:8080';
        putenv(self::NAME . '=http://proxy.internal:3128');

        self::assertSame('http://proxy.internal:3128', Environment::get(self::NAME));
    }

    public function testDotenvSetsAnHttpName(): void
    {
        $root = sys_get_temp_dir() . '/renovo-env-' . bin2hex(random_bytes(4));
        mkdir($root);
        // A name of its own: what .env loads stays loaded for the process.
        file_put_contents($root . '/.env', "HTTP_RENOVO_TEST_DOTENV=http://from-dotenv:3128\n");

        try {
            Environment::loadDotenv($root);
        } finally {
            unlink($root . '/.env');
            rmdir($root);
        }

        unset($_SERVER['HTTP_RENOVO_TEST_DOTENV'], $_ENV['HTTP_RENOVO_TEST_DOTENV']);
        self::assertSame('http://from-dotenv:3128', Environment::get('HTTP_RENOVO_TEST_DOTENV'));
    }

    public function testOtherNamesReadEnvThenTheProcessAndNeverServer(): void
    {
        $_SERVER['RENOVO_TEST_VALUE'] = 'from-server';
        self::assertNull(Environment::get('RENOVO_TEST_VALUE'));

        putenv('RENOVO_TEST_VALUE=from-process');
        self::assertSame('from-process', Environment::get('RENOVO_TEST_VALUE'));

        $_ENV['RENOVO_TEST_VALUE'] = 'from-env';
        self::assertSame('from-env', Environment::get('RENOVO_TEST_VALUE'));
    }

    public function testAMissingDotenvIsNotAnError(): void
    {
        Environment::loadDotenv(sys_get_temp_dir() . '/renovo-no-such-directory');

        self::assertNull(Environment::get('RENOVO_TEST_UNSET'));
    }
}
