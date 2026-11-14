<?php

declare(strict_types=1);

namespace App\Service;

use GdImage;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use App\Support\UploadRefusal;

/**
 * Where a member's picture lives, and what it has been turned into first.
 *
 * Not under `public/`, unlike a subscription's logo, and the difference is not
 * fussiness. A logo is a company's mark and its URL discloses nothing; an
 * avatar on a family instance is a photograph of a child. It is served by a
 * route that asks who is looking — see the streaming route — and the bytes
 * never sit anywhere the web server can reach on its own.
 *
 * **Nothing that arrives is stored.** The upload is decoded by GD and a new
 * image is drawn from the pixels, which is the strongest of the three defences
 * here and the reason the phase takes a dependency on `ext-gd`:
 *
 *  - The type comes from the bytes, via `getimagesize()`, never from the name
 *    or the declared MIME type.
 *  - Re-encoding discards everything that is not a pixel. EXIF — which on a
 *    phone photograph includes where it was taken — is gone, as is any payload
 *    hidden in a comment field, and a polyglot file that is both a valid PNG
 *    and a valid script stops being the second one.
 *  - The result is square, capped, and always PNG, so a 4000-pixel portrait
 *    does not become a 4000-pixel avatar and the extension is not a variable.
 */
final class AvatarStorage
{
    /**
     * What the screen actually needs, doubled for a high-density display. An
     * avatar is drawn at 40 pixels in the member list and 32 in the top bar;
     * storing more than this is storing a photograph, not an avatar.
     */
    public const DISPLAY_PIXELS = 256;

    /** @var array<int, true> */
    private const ALLOWED_TYPES = [
        IMAGETYPE_PNG => true,
        IMAGETYPE_JPEG => true,
        IMAGETYPE_WEBP => true,
        IMAGETYPE_GIF => true,
    ];

    /**
     * A ceiling on the decoded pixel count, independent of the byte cap.
     *
     * A "decompression bomb" is a small file that decodes enormous: 10KB of
     * PNG can describe 40,000 by 40,000 pixels, which GD will happily try to
     * allocate about six gigabytes for. The byte limit does not see that
     * coming, so the dimensions are checked before anything is decoded.
     */
    private const MAX_SOURCE_PIXELS = 50_000_000;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes,
    ) {
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Take an upload, re-encode it, and file the result.
     *
     * @return string The path relative to the avatar directory.
     * @throws ValidationException
     */
    public function store(UploadedFileInterface $file, int $userId): string
    {
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::field('avatar', 'error.file.required');
        }

        if (UploadRefusal::isTooLarge($file)) {
            throw $this->tooLarge();
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationException::field('avatar', 'error.upload.failed');
        }

        $declared = $file->getSize();
        if ($declared !== null && $declared > $this->maxBytes) {
            throw $this->tooLarge();
        }

        $temporary = tempnam(sys_get_temp_dir(), 'avatar');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file for the upload.');
        }

        try {
            $file->moveTo($temporary);

            // Re-checked after the move: getSize() reports what the client
            // claimed, and the client is not the authority on that either.
            if ((int) filesize($temporary) > $this->maxBytes) {
                throw $this->tooLarge();
            }

            $square = $this->decodeToSquare($temporary);

            return $this->place($square, $userId);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * The absolute path of a stored avatar, or null when the stored value is
     * not one this class would have produced.
     *
     * The pattern check is what stops a `avatar_path` that somehow acquired a
     * `../` — a hand-edited row, a bug elsewhere — from reading a file outside
     * the directory.
     */
    public function absolutePath(string $storedPath): ?string
    {
        if (preg_match('#^[0-9]+/[0-9a-f]{32}\.png$#', $storedPath) !== 1) {
            return null;
        }

        $absolute = rtrim($this->directory, '/') . '/' . $storedPath;

        return is_file($absolute) ? $absolute : null;
    }

    public function delete(string $storedPath): void
    {
        $absolute = $this->absolutePath($storedPath);
        if ($absolute !== null) {
            @unlink($absolute);
        }
    }

    /**
     * Decode the upload and draw a square from the middle of it.
     *
     * Centre-cropped rather than letterboxed: an avatar is rendered in a
     * circle, and a portrait squeezed into a square would put the face in the
     * top third of a shape that shows the middle.
     *
     * @throws ValidationException
     */
    private function decodeToSquare(string $path): GdImage
    {
        $info = @getimagesize($path);
        $type = is_array($info) ? $info[2] : 0;

        if (!isset(self::ALLOWED_TYPES[$type])) {
            throw ValidationException::field('avatar', 'error.avatar.type');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_SOURCE_PIXELS) {
            throw ValidationException::field('avatar', 'error.avatar.dimensions');
        }

        $source = @imagecreatefromstring((string) file_get_contents($path));
        if ($source === false) {
            throw ValidationException::field('avatar', 'error.avatar.type');
        }

        // No `imagedestroy()` anywhere in this class. It has done nothing since
        // PHP 8.0 — a GdImage is an object and is freed when the last reference
        // to it goes — and it is deprecated as of 8.5, so calling it would be a
        // deprecation notice in exchange for no memory. Same reason
        // AttachmentStorage uses the finfo object rather than finfo_close().
        $side = min($width, $height);
        $size = min(self::DISPLAY_PIXELS, $side);

        $target = imagecreatetruecolor($size, $size);

        // Kept so a PNG with a transparent background does not come back with
        // a black one.
        imagealphablending($target, false);
        imagesavealpha($target, true);

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            intdiv($width - $side, 2),
            intdiv($height - $side, 2),
            $size,
            $size,
            $side,
            $side,
        );

        return $target;
    }

    /**
     * @return string The path relative to the avatar directory.
     */
    private function place(GdImage $image, int $userId): string
    {
        $folder = rtrim($this->directory, '/') . '/' . $userId;

        if (!is_dir($folder) && !mkdir($folder, 0o750, true) && !is_dir($folder)) {
            throw new RuntimeException(sprintf('Avatar directory "%s" is not writable.', $folder));
        }

        $name = bin2hex(random_bytes(16)) . '.png';
        $destination = $folder . '/' . $name;

        if (!imagepng($image, $destination, 6)) {
            throw new RuntimeException('The avatar could not be saved.');
        }

        @chmod($destination, 0o640);

        return $userId . '/' . $name;
    }

    private function tooLarge(): ValidationException
    {
        return ValidationException::field('avatar', 'error.avatar.too_large', [
            'kilobytes' => max(1, intdiv($this->maxBytes, 1024)),
        ]);
    }
}
