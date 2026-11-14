<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * K239: the Docker image's PHP and nginx limits let every upload the
 * application's own limits allow reach the application, so a file just over
 * an `UPLOAD_MAX_*` gets its JSON 422 rather than PHP's "could not be
 * uploaded" or nginx's HTML 413.
 *
 * The functional tests bypass both, so the three files are read here: the
 * defaults in `config/settings.php`, `docker/php/php.ini` and
 * `docker/nginx/default.conf`.
 */
final class UploadServerLimitsTest extends TestCase
{
    public function testTheImagesPhpAndNginxLimitsLetEveryUploadLimitThrough(): void
    {
        $defaults = $this->uploadDefaults();
        // Not vacuous: the three that exist today, attachments the largest.
        self::assertCount(3, $defaults);
        self::assertSame(10485760, max($defaults));

        $ini = $this->read('docker/php/php.ini');
        $uploadMax = $this->bytes($this->match('/^upload_max_filesize\s*=\s*(\S+)/m', $ini));
        $postMax = $this->bytes($this->match('/^post_max_size\s*=\s*(\S+)/m', $ini));
        $bodyMax = $this->bytes($this->match(
            '/^\s*client_max_body_size\s+(\S+);/m',
            $this->read('docker/nginx/default.conf'),
        ));

        foreach ($defaults as $name => $bytes) {
            // Strictly greater: a file one byte over the limit must still
            // reach the application to be refused by it.
            self::assertGreaterThan($bytes, $uploadMax, 'upload_max_filesize does not let ' . $name . ' through.');
        }
        // The request carries the file plus the multipart boundaries and the
        // other fields, so it needs room above the file limit.
        self::assertGreaterThan($uploadMax, $postMax, 'post_max_size is not above upload_max_filesize.');
        self::assertGreaterThanOrEqual($postMax, $bodyMax, 'nginx refuses a body PHP would take.');
    }

    /**
     * Each `UPLOAD_MAX_*` default from `config/settings.php`, in bytes.
     *
     * @return array<string, int>
     */
    private function uploadDefaults(): array
    {
        preg_match_all(
            '/\$env\(\'(UPLOAD_MAX_[A-Z_]+)\',\s*\'(\d+)\'\)/',
            $this->read('config/settings.php'),
            $matches,
        );

        return array_map('intval', array_combine($matches[1], $matches[2]));
    }

    /**
     * A PHP or nginx size (`12M`, `16m`, `512k`, `1g` or plain bytes) in bytes.
     */
    private function bytes(string $size): int
    {
        $units = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3];
        $unit = strtolower(substr($size, -1));

        return isset($units[$unit]) ? (int) substr($size, 0, -1) * $units[$unit] : (int) $size;
    }

    private function match(string $pattern, string $subject): string
    {
        self::assertSame(1, preg_match($pattern, $subject, $match), 'No match for ' . $pattern);

        return $match[1];
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertNotFalse($contents, $path . ' cannot be read.');

        return $contents;
    }
}
