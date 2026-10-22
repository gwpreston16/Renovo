<?php

declare(strict_types=1);

namespace App\Service;

use finfo;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Where invoices and receipts live on disk.
 *
 * Deliberately not under `public/`, unlike logos. A logo is a public-ish image
 * whose URL leaks nothing; an invoice has an address and a card number on it.
 * Nothing here is reachable by the web server, so the only way to read a stored
 * file is the scoped streaming route, which asks the same questions about the
 * subscription that every other read does.
 *
 * Three rules make an upload safe, and all three are enforced here rather than
 * asked of callers:
 *
 *  - The type comes from the bytes. `finfo` reads the file's magic number; what
 *    the client called it and what it claimed the MIME type was are both
 *    ignored. A .pdf full of PHP is rejected because it is not a PDF.
 *  - The name is chosen by this application. Random hex plus an extension
 *    derived from the detected type, so an upload cannot pick its own path,
 *    escape the directory or arrive with a name the web server would execute.
 *  - The household is part of the path, which keeps one household's files from
 *    sharing a directory with another's even at rest.
 */
final class AttachmentStorage
{
    /**
     * The allowed types, keyed by what `finfo` reports.
     *
     * Kept short on purpose: an invoice is a PDF or a photograph of a piece of
     * paper. Office documents are absent because they carry macros, and SVG
     * because it carries script.
     *
     * @var array<string, string>
     */
    private const ALLOWED_TYPES = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return array_keys(self::ALLOWED_TYPES);
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Move an upload into storage.
     *
     * @return array{path: string, mime: string, size: int}
     * @throws ValidationException
     */
    public function store(UploadedFileInterface $file, int $householdId): array
    {
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::field('file', 'error.file.required');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationException::field('file', 'error.upload.failed');
        }

        $size = $file->getSize();
        if ($size !== null && $size > $this->maxBytes) {
            throw $this->tooLarge();
        }

        $temporary = tempnam(sys_get_temp_dir(), 'attach');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file for the upload.');
        }

        $file->moveTo($temporary);

        // Checked again after the move: getSize() reports what the client said
        // the size was, and the client is not the authority on that either.
        $actualSize = (int) filesize($temporary);
        if ($actualSize > $this->maxBytes) {
            @unlink($temporary);

            throw $this->tooLarge();
        }

        $mime = $this->detectType($temporary);
        if ($mime === null) {
            @unlink($temporary);

            throw ValidationException::field('file', 'error.attachment.type');
        }

        return [
            'path' => $this->place($temporary, $householdId, self::ALLOWED_TYPES[$mime]),
            'mime' => $mime,
            'size' => $actualSize,
        ];
    }

    /**
     * File away bytes that are already on disk — the restore path.
     *
     * Identical validation to an upload, and that is the point: an archive is
     * untrusted input however it was produced, so a restored attachment gets
     * the same magic-byte check as one somebody chose in a file picker.
     *
     * @return array{path: string, mime: string, size: int}
     * @throws ValidationException
     */
    public function storeFile(string $sourcePath, int $householdId): array
    {
        $size = (int) @filesize($sourcePath);
        if ($size <= 0 || $size > $this->maxBytes) {
            throw $this->tooLarge();
        }

        $mime = $this->detectType($sourcePath);
        if ($mime === null) {
            throw ValidationException::field('file', 'error.attachment.archive_type');
        }

        $destination = $this->place($sourcePath, $householdId, self::ALLOWED_TYPES[$mime], copy: true);

        return ['path' => $destination, 'mime' => $mime, 'size' => $size];
    }

    /**
     * The absolute path of a stored file, or null when the stored path is not
     * one this class would have produced.
     *
     * The pattern check is not decoration. It is what stops a `stored_path`
     * that somehow acquired a `../` — through a restored archive, a hand-edited
     * row, a bug elsewhere — from reading a file outside the directory.
     */
    public function absolutePath(string $storedPath): ?string
    {
        if (preg_match('#^[0-9]+/[0-9a-f]{32}\.(pdf|png|jpg|webp|gif)$#', $storedPath) !== 1) {
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
     * @return string The path relative to the attachment directory.
     */
    private function place(string $source, int $householdId, string $extension, bool $copy = false): string
    {
        $folder = rtrim($this->directory, '/') . '/' . $householdId;

        if (!is_dir($folder) && !mkdir($folder, 0o750, true) && !is_dir($folder)) {
            if (!$copy) {
                @unlink($source);
            }

            throw new RuntimeException(sprintf('Attachment directory "%s" is not writable.', $folder));
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $folder . '/' . $name;

        // Silenced: a failure is reported by the exception below, not a warning.
        $moved = $copy ? @copy($source, $destination) : @rename($source, $destination);
        if (!$moved) {
            if (!$copy) {
                @unlink($source);
            }

            throw new RuntimeException('The file could not be saved.');
        }

        @chmod($destination, 0o640);

        return $householdId . '/' . $name;
    }

    /**
     * The object API rather than `finfo_open()`/`finfo_close()`: the procedural
     * close was deprecated in PHP 8.5, and an instance frees itself.
     */
    private function detectType(string $path): ?string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (!is_string($detected) || !isset(self::ALLOWED_TYPES[$detected])) {
            return null;
        }

        return $detected;
    }

    private function tooLarge(): ValidationException
    {
        return ValidationException::field('file', 'error.attachment.too_large', [
            'megabytes' => max(1, intdiv($this->maxBytes, 1024 * 1024)),
        ]);
    }
}
