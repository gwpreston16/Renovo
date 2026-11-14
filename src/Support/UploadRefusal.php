<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Tells an upload PHP refused for its size apart from one that failed.
 *
 * A file over PHP's own `upload_max_filesize` (or a form's MAX_FILE_SIZE)
 * never reaches the application's `UPLOAD_MAX_*` check: PHP drops it and
 * reports an error code instead. Read as a generic failure, that says "Try
 * again", and a retry fails the same way. The stores answer it with their
 * own "too large" message instead, naming the limit that actually applies:
 * PHP's own when an operator raised an `UPLOAD_MAX_*` above it, or on a
 * server left at PHP's 2M default, since the application's would be a false
 * promise there.
 */
final class UploadRefusal
{
    /**
     * Whether PHP refused the file for being larger than it takes.
     */
    public static function isTooLarge(UploadedFileInterface $file): bool
    {
        return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    }

    /**
     * The largest file that gets through, in bytes: the smaller of the
     * application's own limit and PHP's `upload_max_filesize`.
     *
     * @param string|null $phpLimit PHP's setting as written (`12M`, `512K`,
     *                              plain bytes); null reads it from PHP.
     */
    public static function ceiling(int $appLimit, ?string $phpLimit = null): int
    {
        $php = self::bytes($phpLimit ?? (string) ini_get('upload_max_filesize'));

        // 0, or a value that is not a size, is no limit of PHP's own.
        return $php > 0 ? min($appLimit, $php) : $appLimit;
    }

    /**
     * A php.ini size (`12M`, `512k`, `1G` or plain bytes) in bytes, 0 when
     * it is not one.
     */
    private static function bytes(string $size): int
    {
        if (preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/i', $size, $match) !== 1) {
            return 0;
        }

        $power = ['' => 0, 'k' => 1, 'm' => 2, 'g' => 3][strtolower($match[2])];

        return (int) $match[1] * 1024 ** $power;
    }
}
