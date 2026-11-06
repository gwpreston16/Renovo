<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * K223: every `UPLOAD_MAX_*` setting an operator can change is written down
 * where operators look, and the API pages state the two limits a client meets.
 *
 * The settings are read from `config/settings.php` rather than listed here, so
 * a limit added later fails this test until it is documented.
 */
final class UploadLimitDocsTest extends TestCase
{
    public function testEveryUploadLimitIsOnTheConfigurationPageAndInTheExampleEnv(): void
    {
        $names = $this->uploadSettings();

        // Not vacuous: the three that exist today.
        self::assertContains('UPLOAD_MAX_LOGO_BYTES', $names);
        self::assertContains('UPLOAD_MAX_ATTACHMENT_BYTES', $names);
        self::assertContains('UPLOAD_MAX_AVATAR_BYTES', $names);

        $configuration = $this->read('docs/configuration.md');
        $example = $this->read('.env.example');

        foreach ($names as $name) {
            self::assertStringContainsString('`' . $name . '`', $configuration, $name . ' is not in docs/configuration.md.');
            self::assertStringContainsString($name . '=', $example, $name . ' is not in .env.example.');
        }
    }

    public function testTheApiPagesStateTheLogoAndAttachmentLimits(): void
    {
        $prose = $this->read('docs/api.md');
        $spec = $this->read('openapi/openapi.yaml');

        foreach (['UPLOAD_MAX_LOGO_BYTES' => '1048576', 'UPLOAD_MAX_ATTACHMENT_BYTES' => '10485760'] as $name => $bytes) {
            self::assertStringContainsString($name, $prose);
            self::assertStringContainsString($name, $spec);
            self::assertStringContainsString($bytes, $spec);
        }
    }

    /**
     * @return list<string>
     */
    private function uploadSettings(): array
    {
        preg_match_all('/\$env\(\'(UPLOAD_MAX_[A-Z_]+)\'/', $this->read('config/settings.php'), $matches);

        return array_values(array_unique($matches[1]));
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertNotFalse($contents, $path . ' cannot be read.');

        return $contents;
    }
}
