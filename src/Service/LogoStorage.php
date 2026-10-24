<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Stores a logo that the user uploaded.
 *
 * Uploaded only — this phase never fetches a logo from a URL. Fetching one
 * means making an outbound request to an address a user chose, which needs the
 * SSRF protections that arrive with the phase that introduces it. The seam is
 * here: a future fetcher writes into the same directory through this class.
 *
 * The stored file is renamed to a random name with an extension derived from
 * the detected image type, never from what the client claimed, so an upload
 * cannot choose its own path or masquerade as a script.
 */
final class LogoStorage
{
    /** @var array<int, string> */
    private const ALLOWED_TYPES = [
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @return string|null The web-relative path to store on the subscription.
     * @throws ValidationException
     */
    public function store(?UploadedFileInterface $file): ?string
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationException::field('logo', 'error.logo.upload_failed');
        }

        $size = $file->getSize();
        if ($size !== null && $size > $this->maxBytes) {
            throw ValidationException::field(
                'logo',
                'error.logo.too_large',
                ['kilobytes' => intdiv($this->maxBytes, 1024)],
            );
        }

        $temporary = tempnam(sys_get_temp_dir(), 'logo');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file for the upload.');
        }

        $file->moveTo($temporary);

        $info = @getimagesize($temporary);
        $detectedType = is_array($info) ? $info[2] : 0;

        if (!isset(self::ALLOWED_TYPES[$detectedType])) {
            @unlink($temporary);

            throw ValidationException::field('logo', 'error.logo.type');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            @unlink($temporary);

            throw new RuntimeException(sprintf('Logo directory "%s" is not writable.', $this->directory));
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_TYPES[$detectedType];
        $destination = rtrim($this->directory, '/') . '/' . $name;

        if (!rename($temporary, $destination)) {
            @unlink($temporary);

            throw new RuntimeException('The logo could not be saved.');
        }

        @chmod($destination, 0o644);

        return 'assets/logos/' . $name;
    }

    /**
     * File away an image that is already on disk — the restore path.
     *
     * Identical validation to an upload, deliberately: a logo out of a backup
     * archive is untrusted input however the archive was produced, so it gets
     * the same `getimagesize()` check and the same application-chosen name. An
     * image that does not pass returns null and the subscription restores
     * without it, rather than failing the whole run over a picture.
     *
     * @return string|null The web-relative path, or null when it is not an image.
     */
    public function storeFile(string $sourcePath): ?string
    {
        $size = @filesize($sourcePath);
        if ($size === false || $size <= 0 || $size > $this->maxBytes) {
            return null;
        }

        $info = @getimagesize($sourcePath);
        $detectedType = is_array($info) ? $info[2] : 0;

        if (!isset(self::ALLOWED_TYPES[$detectedType])) {
            return null;
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Logo directory "%s" is not writable.', $this->directory));
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_TYPES[$detectedType];

        if (!copy($sourcePath, rtrim($this->directory, '/') . '/' . $name)) {
            return null;
        }

        @chmod(rtrim($this->directory, '/') . '/' . $name, 0o644);

        return 'assets/logos/' . $name;
    }

    /**
     * Whether a path is one `store()` or `storeFile()` could have returned.
     *
     * Anything else did not come from here — a path typed into a form, say —
     * and is neither saved against a subscription nor deleted (K1).
     */
    public static function isStoredPath(?string $path): bool
    {
        return $path !== null && preg_match('~^assets/logos/[0-9a-f]{32}\.(png|jpg|gif|webp)$~D', $path) === 1;
    }

    /**
     * Remove a stored logo. Paths that did not come from `store()` are
     * ignored rather than trusted.
     */
    public function delete(?string $storedPath): void
    {
        if (!self::isStoredPath($storedPath)) {
            return;
        }

        @unlink(rtrim($this->directory, '/') . '/' . basename((string) $storedPath));
    }
}
