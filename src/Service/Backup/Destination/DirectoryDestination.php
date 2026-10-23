<?php

declare(strict_types=1);

namespace App\Service\Backup\Destination;

/**
 * A directory on a mount the container can see (Phase 39): a NAS share, a
 * second disk.
 *
 * A copy goes to a temporary name in the same directory, is flushed to disk,
 * has its SHA-256 compared with the backup's, and only then is renamed into
 * place. A copy interrupted at any point leaves nothing under the final name.
 */
final class DirectoryDestination implements BackupDestination
{
    use RoundTripTest;

    public function __construct(private readonly string $path)
    {
    }

    public function put(string $localPath, string $object, string $sha256, int $size): void
    {
        $this->ready(DestinationException::STEP_WRITE);
        $final = $this->file($object);
        $temporary = $this->path . '/.' . $object . '.' . bin2hex(random_bytes(4)) . '.partial';

        try {
            $this->copy($localPath, $temporary, DestinationException::STEP_WRITE, true);

            if (hash_file('sha256', $temporary) !== $sha256 || filesize($temporary) !== $size) {
                throw new DestinationException(
                    DestinationException::STEP_VERIFY,
                    'checksum',
                    'The copy written does not match the backup.',
                );
            }

            if (!@rename($temporary, $final)) {
                throw new DestinationException(
                    DestinationException::STEP_WRITE,
                    'write_failed',
                    'The copy could not be moved into place.',
                );
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function head(string $object): ?int
    {
        $this->ready(DestinationException::STEP_READ);
        $file = $this->file($object);
        clearstatcache(true, $file);

        $size = is_file($file) ? @filesize($file) : false;

        return $size === false ? null : $size;
    }

    public function get(string $object, string $localPath): void
    {
        $this->ready(DestinationException::STEP_READ);
        $file = $this->file($object);
        if (!is_file($file)) {
            throw new DestinationException(DestinationException::STEP_READ, 'not_found', 'There is no such copy.');
        }

        $this->copy($file, $localPath, DestinationException::STEP_READ, false);
        @chmod($localPath, 0o600);
    }

    public function delete(string $object): void
    {
        $this->ready(DestinationException::STEP_DELETE);
        $file = $this->file($object);

        if (is_file($file) && !@unlink($file)) {
            throw new DestinationException(
                DestinationException::STEP_DELETE,
                'write_failed',
                'The copy could not be removed.',
            );
        }
    }

    private function ready(string $step): void
    {
        if (!is_dir($this->path)) {
            throw new DestinationException(
                DestinationException::STEP_CONNECT,
                'missing',
                'The directory does not exist.',
            );
        }
        if ($step !== DestinationException::STEP_READ && !is_writable($this->path)) {
            throw new DestinationException(
                DestinationException::STEP_CONNECT,
                'not_writable',
                'The directory is not writable.',
            );
        }
    }

    /**
     * Only names a backup has — a single path segment — are ever used.
     */
    private function file(string $object): string
    {
        $hidden = str_starts_with($object, '.') && $object !== self::MARKER;
        if ($object === '' || $hidden || str_contains($object, '/') || str_contains($object, "\0")) {
            throw new DestinationException(DestinationException::STEP_CONNECT, 'refused', 'That is not a backup name.');
        }

        return $this->path . '/' . $object;
    }

    private function copy(string $from, string $to, string $step, bool $sync): void
    {
        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'xb');
        try {
            if ($in === false || $out === false) {
                throw new DestinationException($step, 'write_failed', 'The copy could not be opened.');
            }
            if (stream_copy_to_stream($in, $out) === false || !fflush($out) || ($sync && !fsync($out))) {
                throw new DestinationException($step, 'write_failed', 'The copy could not be written.');
            }
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }
}
