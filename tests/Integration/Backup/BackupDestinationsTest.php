<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backup;

use App\Http\GuardedTransferClient;
use App\Http\HttpClient;
use App\Http\HttpClientOptions;
use App\Http\StaticTrustedTargets;
use App\Http\UrlGuard;
use App\Service\Backup\Destination\BackupDestination;
use App\Service\Backup\Destination\DestinationException;
use App\Service\Backup\Destination\DirectoryDestination;
use App\Service\Backup\Destination\HttpExchange;
use App\Service\Backup\Destination\S3Destination;
use App\Service\Backup\Destination\S3Signer;
use App\Service\Backup\Destination\WebDavDestination;
use App\Support\SystemClock;
use App\Tests\Support\FakeDnsResolver;
use App\Tests\Support\LocalServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * The three destinations against real storage (Phase 39): a WebDAV server on
 * loopback, a directory, and — when `TEST_S3_*` names one — an S3-compatible
 * store such as the MinIO container CI starts (docs/development.md).
 *
 * Every HTTP destination goes through the guard: loopback is reachable here
 * only because the test's trusted-host list names it.
 */
final class BackupDestinationsTest extends TestCase
{
    private const USER = 'backup';
    private const PASSWORD = 'canary-webdav-password-7d1c';

    private static LocalServer $webdav;

    private static string $webdavRoot;

    private string $work;

    public static function setUpBeforeClass(): void
    {
        self::$webdavRoot = sys_get_temp_dir() . '/renovo-webdav-' . bin2hex(random_bytes(4));
        mkdir(self::$webdavRoot, 0o700);
        self::$webdav = new LocalServer('webdav.php', [
            'WEBDAV_ROOT' => self::$webdavRoot,
            'WEBDAV_USER' => self::USER,
            'WEBDAV_PASSWORD' => self::PASSWORD,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$webdav->stop();
        self::removeTree(self::$webdavRoot);
    }

    protected function setUp(): void
    {
        self::$webdav->clearRequests();
        $this->work = sys_get_temp_dir() . '/renovo-destination-' . bin2hex(random_bytes(4));
        mkdir($this->work, 0o700);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->work);
    }

    public function testWebDavStoresChecksReadsAndDeletes(): void
    {
        $this->assertRoundTrip($this->webdav());
    }

    public function testWebDavTestConnectionPassesAndLeavesNothing(): void
    {
        $this->webdav()->test();

        self::assertSame([], array_values(array_diff((array) scandir(self::$webdavRoot), ['.', '..'])));
    }

    public function testWebDavWithTheWrongPasswordFailsAtTheWriteStep(): void
    {
        $destination = $this->webdav('/dav/', 'not-the-password');

        try {
            $destination->test();
            self::fail('A wrong password connected.');
        } catch (DestinationException $exception) {
            self::assertSame(DestinationException::STEP_WRITE, $exception->step);
            self::assertSame('denied', $exception->reason);
            self::assertStringNotContainsString('not-the-password', $exception->getMessage());
        }
    }

    public function testWebDavRefusesAnUploadTheServerReportsAtTheWrongSize(): void
    {
        $file = $this->file(random_bytes(2000));

        try {
            $this->webdav('/lying/')->put($file, 'renovo-instance-x.renovo-backup', hash_file('sha256', $file), 2000);
            self::fail('A size mismatch was accepted.');
        } catch (DestinationException $exception) {
            self::assertSame(DestinationException::STEP_VERIFY, $exception->step);
            self::assertSame('size', $exception->reason);
        }
    }

    public function testAPrivateAddressIsRefusedUnlessItIsTrusted(): void
    {
        $destination = new WebDavDestination(
            $this->exchange([]),
            new RequestFactory(),
            new StreamFactory(),
            self::$webdav->url('/dav/', 'nas.test'),
            self::USER,
            self::PASSWORD,
        );

        try {
            $destination->test();
            self::fail('An untrusted private address was reached.');
        } catch (DestinationException $exception) {
            self::assertSame(DestinationException::STEP_CONNECT, $exception->step);
            self::assertSame('blocked', $exception->reason);
        }

        self::assertSame([], self::$webdav->requests());
    }

    public function testPlainHttpIsRefusedForAHostThatIsNotTrusted(): void
    {
        // A public address, so only the scheme can be the reason.
        $destination = new WebDavDestination(
            $this->exchange([], ['dav.example' => ['93.184.216.34']]),
            new RequestFactory(),
            new StreamFactory(),
            'http://dav.example/remote.php/dav/files/me/',
            self::USER,
            self::PASSWORD,
        );

        $this->expectException(DestinationException::class);
        $this->expectExceptionMessage('http');

        $destination->head('anything');
    }

    public function testWebDavPropfindAnswersAreReadWhateverThePrefix(): void
    {
        self::assertSame(42, WebDavDestination::contentLength(
            '<?xml version="1.0"?><x:multistatus xmlns:x="DAV:"><x:response><x:propstat><x:prop>'
            . '<x:getcontentlength>42</x:getcontentlength></x:prop></x:propstat></x:response></x:multistatus>',
        ));
        self::assertNull(WebDavDestination::contentLength(''));
        self::assertNull(WebDavDestination::contentLength('not xml'));
        self::assertNull(WebDavDestination::contentLength(
            '<a xmlns="DAV:"><getcontentlength>-1</getcontentlength></a>',
        ));
    }

    public function testDirectoryStoresChecksReadsAndDeletes(): void
    {
        $target = $this->work . '/nas';
        mkdir($target);

        $this->assertRoundTrip(new DirectoryDestination($target));
        (new DirectoryDestination($target))->test();
        self::assertSame(['.', '..'], scandir($target));
    }

    public function testAnInterruptedDirectoryCopyLeavesNoFileUnderTheFinalName(): void
    {
        $target = $this->work . '/nas';
        mkdir($target);
        $file = $this->file(random_bytes(5000));

        try {
            // The checksum it is told is not the file's: the copy is written
            // and checked, and never renamed into place.
            (new DirectoryDestination($target))
                ->put($file, 'renovo-instance-a.renovo-backup', str_repeat('0', 64), 5000);
            self::fail('A copy that does not match was kept.');
        } catch (DestinationException $exception) {
            self::assertSame(DestinationException::STEP_VERIFY, $exception->step);
        }

        self::assertSame(['.', '..'], scandir($target));
    }

    public function testADirectoryThatIsMissingOrReadOnlyFailsToConnect(): void
    {
        try {
            (new DirectoryDestination($this->work . '/absent'))->test();
            self::fail('A missing directory connected.');
        } catch (DestinationException $exception) {
            self::assertSame(DestinationException::STEP_CONNECT, $exception->step);
            self::assertSame('missing', $exception->reason);
        }

        $readOnly = $this->work . '/ro';
        mkdir($readOnly, 0o500);
        try {
            (new DirectoryDestination($readOnly))->test();
            self::fail('A read-only directory connected.');
        } catch (DestinationException $exception) {
            self::assertSame('not_writable', $exception->reason);
        } finally {
            chmod($readOnly, 0o700);
        }
    }

    public function testADirectoryDestinationOnlyEverUsesABackupName(): void
    {
        $this->expectException(DestinationException::class);

        (new DirectoryDestination($this->work))->delete('../escape');
    }

    public function testS3StoresReadsAndDeletes(): void
    {
        $this->assertRoundTrip($this->s3());
    }

    public function testS3TestConnectionPasses(): void
    {
        $this->s3()->test();
        self::assertNull($this->s3()->head(BackupDestination::MARKER));
    }

    public function testTheStorageServiceRejectsABodyThatDoesNotMatchItsSignedHash(): void
    {
        $file = $this->file('the backup as it was checked');

        try {
            $this->s3()->put($file, 'renovo-instance-tampered.renovo-backup', hash('sha256', 'something else'), 28);
            self::fail('The service accepted a body that does not match its hash.');
        } catch (DestinationException $exception) {
            self::assertSame(DestinationException::STEP_WRITE, $exception->step);
            self::assertSame('checksum', $exception->reason);
        }

        self::assertNull($this->s3()->head('renovo-instance-tampered.renovo-backup'));
    }

    public function testS3WithTheWrongSecretIsDeniedAndTheSecretIsNotInTheMessage(): void
    {
        try {
            $this->s3('canary-wrong-secret-91ab')->test();
            self::fail('A wrong secret connected.');
        } catch (DestinationException $exception) {
            self::assertSame(DestinationException::STEP_WRITE, $exception->step);
            self::assertSame('denied', $exception->reason);
            self::assertStringNotContainsString('canary-wrong-secret-91ab', $exception->getMessage());
        }
    }

    public function testS3UrlsArePathOrVirtualHostStyle(): void
    {
        $exchange = $this->exchange([]);
        $make = static fn (bool $pathStyle): S3Destination => new S3Destination(
            $exchange,
            new RequestFactory(),
            new SystemClock(new \DateTimeZone('UTC')),
            'https://s3.eu-west-2.amazonaws.com',
            'eu-west-2',
            'my-bucket',
            'renovo/',
            $pathStyle,
            'key',
            'secret',
        );

        self::assertSame(
            'https://s3.eu-west-2.amazonaws.com/my-bucket/renovo/renovo-instance-a.renovo-backup',
            $make(true)->url('renovo-instance-a.renovo-backup'),
        );
        self::assertSame(
            'https://my-bucket.s3.eu-west-2.amazonaws.com/renovo/renovo-instance-a.renovo-backup',
            $make(false)->url('renovo-instance-a.renovo-backup'),
        );
    }

    private function assertRoundTrip(BackupDestination $destination): void
    {
        $contents = random_bytes(150_000);
        $file = $this->file($contents);
        $name = 'renovo-instance-20261023-120000-abcdef.renovo-backup';

        $destination->put($file, $name, hash('sha256', $contents), strlen($contents));
        self::assertSame(strlen($contents), $destination->head($name));

        $back = $this->work . '/back';
        $destination->get($name, $back);
        self::assertSame(hash('sha256', $contents), hash_file('sha256', $back));

        $destination->delete($name);
        self::assertNull($destination->head($name));
        $destination->delete($name);

        try {
            $destination->get($name, $this->work . '/gone');
            self::fail('A deleted object was read.');
        } catch (DestinationException $exception) {
            self::assertSame('not_found', $exception->reason);
        }
        self::assertFileDoesNotExist($this->work . '/gone');
    }

    private function webdav(string $folder = '/dav/', string $password = self::PASSWORD): WebDavDestination
    {
        return new WebDavDestination(
            $this->exchange(['nas.test']),
            new RequestFactory(),
            new StreamFactory(),
            self::$webdav->url($folder, 'nas.test'),
            self::USER,
            $password,
        );
    }

    private function s3(?string $secret = null): S3Destination
    {
        $endpoint = (string) getenv('TEST_S3_ENDPOINT');
        if ($endpoint === '') {
            self::markTestSkipped('Set TEST_S3_ENDPOINT, TEST_S3_KEY, TEST_S3_SECRET and TEST_S3_BUCKET to run.');
        }

        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $exchange = $this->exchange([$host]);
        $destination = new S3Destination(
            $exchange,
            new RequestFactory(),
            new SystemClock(new \DateTimeZone('UTC')),
            $endpoint,
            'us-east-1',
            (string) getenv('TEST_S3_BUCKET'),
            'phase39-tests/',
            true,
            (string) getenv('TEST_S3_KEY'),
            $secret ?? (string) getenv('TEST_S3_SECRET'),
        );
        $this->ensureBucket($exchange, $endpoint);

        return $destination;
    }

    private function ensureBucket(HttpExchange $exchange, string $endpoint): void
    {
        $signer = new S3Signer((string) getenv('TEST_S3_KEY'), (string) getenv('TEST_S3_SECRET'), 'us-east-1');
        $request = (new RequestFactory())->createRequest(
            'PUT',
            rtrim($endpoint, '/') . '/' . rawurlencode((string) getenv('TEST_S3_BUCKET')),
        );
        // 200 when made, 409 when it already exists: either will do.
        $exchange->send($signer->sign($request, S3Signer::EMPTY_PAYLOAD, new \DateTimeImmutable()), 'write');
    }

    /**
     * @param list<string> $trusted
     * @param array<string, list<string>> $dns
     */
    private function exchange(array $trusted, array $dns = []): HttpExchange
    {
        $options = new HttpClientOptions();
        $resolver = new FakeDnsResolver($dns + ['nas.test' => ['127.0.0.1'], 'localhost' => ['127.0.0.1']]);

        return new HttpExchange(new GuardedTransferClient(
            new HttpClient(new ResponseFactory(), new StreamFactory(), $options),
            new UrlGuard(
                $resolver,
                new StaticTrustedTargets([...$trusted, ...$this->literalTrust($trusted)]),
                $options,
                new NullLogger(),
            ),
            $options->forTransfers(120, 5 * 1024 ** 3),
        ));
    }

    /**
     * A literal address in the trusted list is matched as a range.
     *
     * @param list<string> $trusted
     * @return list<string>
     */
    private function literalTrust(array $trusted): array
    {
        return array_values(array_map(
            static fn (string $host): string => str_contains($host, ':') ? $host . '/128' : $host . '/32',
            array_filter($trusted, static fn (string $host): bool => filter_var($host, FILTER_VALIDATE_IP) !== false),
        ));
    }

    private function file(string $contents): string
    {
        $path = $this->work . '/upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);

        return $path;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? self::removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
