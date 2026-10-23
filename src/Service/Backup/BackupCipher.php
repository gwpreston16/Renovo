<?php

declare(strict_types=1);

namespace App\Service\Backup;

use SodiumException;

/**
 * The `*.renovo-backup` file format: a ZIP, encrypted (Phase 38).
 *
 * libsodium's secretstream (XChaCha20-Poly1305), a chunk at a time, so an
 * archive of any size passes through in 64 KiB pieces and never sits in
 * memory. The file is:
 *
 *     "RNVOBKUP"  format version (1 byte)  key fingerprint (8 hex)
 *     secretstream header (24 bytes)
 *     chunk, chunk, … each 64 KiB of plaintext + 17 bytes, the last shorter
 *
 * The first 17 bytes are passed to every chunk as additional data, so the
 * header is authenticated with the content: changing the version or the
 * fingerprint fails decryption exactly as a flipped byte in a chunk does.
 *
 * Only the last chunk carries `TAG_FINAL`, and decryption insists on it being
 * the last thing in the file. Without that, a file cut off at a chunk boundary
 * would decrypt "successfully" to the first part of an archive.
 *
 * Not final: a test stands in a cipher that damages what it writes, which is
 * the only way to watch a run fail its own verification.
 */
class BackupCipher
{
    public const EXTENSION = '.renovo-backup';

    private const MAGIC = 'RNVOBKUP';
    private const VERSION = 1;
    private const CHUNK = 65536;
    private const FINGERPRINT_LENGTH = 8;
    private const PREFIX_LENGTH = 17;

    /**
     * @throws BackupException
     */
    public function encryptFile(string $plainPath, string $encryptedPath, BackupKey $key): void
    {
        $in = $this->open($plainPath, 'rb');
        $out = $this->open($encryptedPath, 'wb');

        try {
            $prefix = self::MAGIC . chr(self::VERSION) . $key->fingerprint;
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key->streamKey());
            $this->write($out, $prefix . $header);

            $chunk = $this->read($in, self::CHUNK);
            do {
                $next = $this->read($in, self::CHUNK);
                $last = $next === '' && feof($in);
                $this->write($out, sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    $prefix,
                    $last
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                ));
                $chunk = $next;
            } while (!$last);

            if (!fflush($out)) {
                throw new BackupException('write_failed', 'The encrypted backup could not be written.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * The key fingerprint a file was written under, from its header, without
     * a key — so "this needs key 1a2b3c4d" can be said before trying one.
     *
     * @throws BackupException
     */
    public function fingerprintOf(string $encryptedPath): string
    {
        $in = $this->open($encryptedPath, 'rb');

        try {
            return $this->readPrefix($in)['fingerprint'];
        } finally {
            fclose($in);
        }
    }

    /**
     * @throws BackupException
     */
    public function decryptFile(string $encryptedPath, string $plainPath, BackupKey $key): void
    {
        $in = $this->open($encryptedPath, 'rb');
        // The plaintext is every row and file: created new (never through a
        // link someone planted, never over an existing file) and private
        // before the first byte is written.
        $out = $this->open($plainPath, 'xb');
        @chmod($plainPath, 0o600);

        try {
            $prefix = $this->readPrefix($in);

            if (!hash_equals($prefix['fingerprint'], $key->fingerprint)) {
                throw new BackupException('wrong_key', sprintf(
                    'This backup was encrypted with the key whose fingerprint is %s; the key configured is %s.',
                    $prefix['fingerprint'],
                    $key->fingerprint,
                ));
            }

            $header = $this->read($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw $this->truncated();
            }

            try {
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key->streamKey());
            } catch (SodiumException) {
                throw $this->tampered();
            }

            $finished = false;
            while (!$finished) {
                $chunk = $this->read($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ($chunk === '') {
                    throw $this->truncated();
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk, $prefix['bytes']);
                if ($result === false) {
                    throw $this->tampered();
                }

                [$plain, $tag] = $result;
                $this->write($out, $plain);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if ($this->read($in, 1) !== '') {
                throw new BackupException(
                    'tampered',
                    'The backup has data after its final chunk; it has been altered.',
                );
            }
        } catch (BackupException $exception) {
            // Whatever decrypted before the failure is plaintext of a file
            // that is not trusted; none of it is left behind.
            fclose($out);
            @unlink($plainPath);

            throw $exception;
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /**
     * @param resource $in
     * @return array{bytes: string, fingerprint: string}
     */
    private function readPrefix($in): array
    {
        $prefix = $this->read($in, self::PREFIX_LENGTH);

        if (strlen($prefix) !== self::PREFIX_LENGTH || !str_starts_with($prefix, self::MAGIC)) {
            throw new BackupException('not_a_backup', 'This file is not an instance backup.');
        }

        if (ord($prefix[strlen(self::MAGIC)]) !== self::VERSION) {
            throw new BackupException(
                'unsupported_version',
                'This backup was written in a format this version of Renovo does not read.',
            );
        }

        $fingerprint = substr($prefix, strlen(self::MAGIC) + 1, self::FINGERPRINT_LENGTH);
        if (!ctype_xdigit($fingerprint)) {
            throw new BackupException('not_a_backup', 'This file is not an instance backup.');
        }

        return ['bytes' => $prefix, 'fingerprint' => $fingerprint];
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false && $mode === 'xb' && file_exists($path)) {
            throw new BackupException('io', sprintf('%s already exists.', basename($path)));
        }
        if ($handle === false) {
            throw new BackupException('io', sprintf('Could not open %s.', basename($path)));
        }

        return $handle;
    }

    /**
     * A full read of `$length` bytes, or fewer only at the end of the file.
     *
     * @param resource $in
     */
    private function read($in, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($in)) {
            $piece = fread($in, $length - strlen($data));
            if ($piece === false) {
                throw new BackupException('io', 'The backup could not be read.');
            }
            $data .= $piece;
        }

        return $data;
    }

    /**
     * @param resource $out
     */
    private function write($out, string $data): void
    {
        if (fwrite($out, $data) !== strlen($data)) {
            throw new BackupException('write_failed', 'The backup could not be written; is the disk full?');
        }
    }

    private function truncated(): BackupException
    {
        return new BackupException('truncated', 'The backup ends before its last chunk; it is incomplete.');
    }

    private function tampered(): BackupException
    {
        return new BackupException(
            'tampered',
            'The backup does not decrypt: it has been altered or damaged, or the key is not the one it was made with.',
        );
    }
}
