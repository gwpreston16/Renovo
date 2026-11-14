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
 * own "too large" message instead, the one a file just over their limit gets.
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
}
