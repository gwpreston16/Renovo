<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The bundled web image finds the real client address only from proxies an
 * operator lists (K7): the generator that runs when the container starts.
 */
final class NginxConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/renovo-nginx-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * @return array{0: int, 1: string} Exit status and the file written.
     */
    private function generate(?string $proxies): array
    {
        $out = $this->dir . '/real-ip.conf';
        $script = dirname(__DIR__, 2) . '/docker/nginx/30-real-ip.sh';
        $env = 'REAL_IP_CONF=' . escapeshellarg($out)
            . ($proxies === null ? '' : ' WEB_TRUSTED_PROXIES=' . escapeshellarg($proxies));

        exec('env -u WEB_TRUSTED_PROXIES ' . $env . ' sh ' . escapeshellarg($script) . ' 2>/dev/null', $lines, $status);

        return [$status, is_file($out) ? (string) file_get_contents($out) : ''];
    }

    public function testNoAddressIsTrustedByDefault(): void
    {
        foreach ([null, '', '  ,  '] as $empty) {
            [$status, $conf] = $this->generate($empty);

            self::assertSame(0, $status);
            self::assertStringNotContainsString('set_real_ip_from', $conf);
            self::assertStringNotContainsString('real_ip_header', $conf);
        }

        $site = (string) file_get_contents(dirname(__DIR__, 2) . '/docker/nginx/default.conf');
        self::assertStringNotContainsString('set_real_ip_from', $site);
    }

    public function testListedProxiesFeedRealIp(): void
    {
        [$status, $conf] = $this->generate('172.18.0.0/16, 10.0.0.5 ,fd00::/8');

        self::assertSame(0, $status);
        self::assertStringContainsString("set_real_ip_from 172.18.0.0/16;\n", $conf);
        self::assertStringContainsString("set_real_ip_from 10.0.0.5;\n", $conf);
        self::assertStringContainsString("set_real_ip_from fd00::/8;\n", $conf);
        self::assertStringContainsString("real_ip_header X-Forwarded-For;\n", $conf);
        self::assertStringContainsString("real_ip_recursive on;\n", $conf);
    }

    public function testAnEntryThatIsNotAnAddressStopsTheContainer(): void
    {
        [$status] = $this->generate("10.0.0.1; } server { listen 81");

        self::assertNotSame(0, $status);
    }

    public function testTheImageAndComposeFileWireItUp(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertStringContainsString(
            '/docker-entrypoint.d/30-real-ip.sh',
            (string) file_get_contents($root . '/docker/php/Dockerfile'),
        );
        self::assertStringContainsString(
            'WEB_TRUSTED_PROXIES: ${WEB_TRUSTED_PROXIES:-}',
            (string) file_get_contents($root . '/docker-compose.yml'),
        );
    }
}
