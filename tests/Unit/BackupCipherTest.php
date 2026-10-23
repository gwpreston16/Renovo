<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Backup\BackupCipher;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `*.renovo-backup` format (Phase 38): what goes in comes out, and
 * anything else — the wrong key, a cut-off file, one flipped byte — fails.
 */
final class BackupCipherTest extends TestCase
{
    private const KEY = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
    private const OTHER = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

    private string $directory;
    private BackupCipher $cipher;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/renovo-cipher-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700);
        $this->cipher = new BackupCipher();
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->directory);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function sizes(): iterable
    {
        yield 'empty' => [0];
        yield 'one byte' => [1];
        yield 'one chunk exactly' => [65536];
        yield 'a chunk and a byte' => [65537];
        yield 'several chunks' => [3 * 65536 + 123];
    }

    #[DataProvider('sizes')]
    public function testWhatIsEncryptedDecryptsToTheSameBytes(int $size): void
    {
        $plain = $this->file('plain', $size === 0 ? '' : random_bytes($size));
        $this->cipher->encryptFile($plain, $this->directory . '/enc', BackupKey::fromHex(self::KEY));
        $this->cipher->decryptFile($this->directory . '/enc', $this->directory . '/out', BackupKey::fromHex(self::KEY));

        self::assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->directory . '/out'));
        if ($size > 16) {
            self::assertStringNotContainsString(
                (string) file_get_contents($plain),
                (string) file_get_contents($this->directory . '/enc'),
            );
        }
    }

    public function testTheHeaderNamesTheKeyByFingerprint(): void
    {
        $key = BackupKey::fromHex(self::KEY);
        $enc = $this->encrypted('archive');

        self::assertSame($key->fingerprint, $this->cipher->fingerprintOf($enc));
        self::assertSame(substr(hash('sha256', (string) hex2bin(self::KEY)), 0, 8), $key->fingerprint);
    }

    public function testTheWrongKeyIsRefusedByFingerprint(): void
    {
        $this->expectFailure('wrong_key', fn () => $this->cipher->decryptFile(
            $this->encrypted('archive'),
            $this->directory . '/out',
            BackupKey::fromHex(self::OTHER),
        ));
    }

    public function testAFileCutAtAChunkBoundaryIsTruncated(): void
    {
        $enc = $this->encrypted(random_bytes(2 * 65536 + 10));
        $chunk = 65536 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $cut = 17 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + 2 * $chunk;
        file_put_contents($enc, substr((string) file_get_contents($enc), 0, $cut));

        $this->expectFailure('truncated', fn () => $this->decrypt($enc));
    }

    public function testAFileCutMidChunkFails(): void
    {
        $enc = $this->encrypted(random_bytes(100000));
        file_put_contents($enc, substr((string) file_get_contents($enc), 0, -40));

        $this->expectFailure('tampered', fn () => $this->decrypt($enc));
    }

    public function testAFlippedByteFails(): void
    {
        $enc = $this->encrypted(random_bytes(5000));
        $bytes = (string) file_get_contents($enc);
        $bytes[200] = chr(ord($bytes[200]) ^ 0x01);
        file_put_contents($enc, $bytes);

        $this->expectFailure('tampered', fn () => $this->decrypt($enc));
    }

    public function testDataAfterTheFinalChunkFails(): void
    {
        $enc = $this->encrypted('archive');
        file_put_contents($enc, 'extra', FILE_APPEND);

        $this->expectFailure('tampered', fn () => $this->decrypt($enc));
    }

    public function testAChangedHeaderVersionIsRefused(): void
    {
        $enc = $this->encrypted('archive');
        $bytes = (string) file_get_contents($enc);
        $bytes[8] = chr(2);
        file_put_contents($enc, $bytes);

        $this->expectFailure('unsupported_version', fn () => $this->decrypt($enc));
    }

    public function testSomethingElseEntirelyIsNotABackup(): void
    {
        $this->expectFailure('not_a_backup', fn () => $this->decrypt($this->file('zip', "PK\x03\x04 not a backup")));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'too short' => ['abc123'];
        yield 'not hex' => [str_repeat('zz', 32)];
        yield 'too long' => [self::KEY . '00'];
    }

    #[DataProvider('badKeys')]
    public function testAnInvalidKeyIsRefusedWithoutRepeatingIt(string $value): void
    {
        try {
            BackupKey::fromHex($value);
            self::fail('An invalid key was accepted.');
        } catch (BackupException $exception) {
            self::assertSame('key_invalid', $exception->reason);
            self::assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    public function testTheKeyIsNotInItsDebugDump(): void
    {
        $key = BackupKey::fromHex(self::KEY);

        self::assertStringNotContainsString(self::KEY, print_r($key, true));
        self::assertStringNotContainsString((string) hex2bin(self::KEY), print_r($key, true));
        self::assertStringNotContainsString((string) hex2bin(self::KEY), var_export($key->__debugInfo(), true));
    }

    private function encrypted(string $contents): string
    {
        $plain = $this->file('plain-' . bin2hex(random_bytes(3)), $contents);
        $enc = $this->directory . '/enc-' . bin2hex(random_bytes(3));
        $this->cipher->encryptFile($plain, $enc, BackupKey::fromHex(self::KEY));

        return $enc;
    }

    private function decrypt(string $enc): void
    {
        $this->cipher->decryptFile($enc, $this->directory . '/out', BackupKey::fromHex(self::KEY));
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function expectFailure(string $reason, callable $work): void
    {
        try {
            $work();
            self::fail('Expected the backup to be refused (' . $reason . ').');
        } catch (BackupException $exception) {
            self::assertSame($reason, $exception->reason, $exception->getMessage());
        }
    }
}
