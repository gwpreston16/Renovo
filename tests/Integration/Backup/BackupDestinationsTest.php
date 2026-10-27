<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backup;

use App\Domain\Entity\BackupDestinationRecord;
use App\Http\GuardedTransferClient;
use App\Http\HttpClient;
use App\Http\HttpClientOptions;
use App\Http\StaticTrustedTargets;
use App\Http\UrlGuard;
use App\Http\OutboundPolicy;
use App\Service\Backup\Destination\BackupDestination;
use App\Service\Backup\Destination\BackupDestinationFactory;
use App\Service\Backup\Destination\DestinationException;
use App\Service\Backup\Destination\DirectoryDestination;
use App\Service\Backup\Destination\HttpExchange;
use App\Service\Backup\Destination\RoundTripTest;
use App\Service\Backup\Destination\S3Destination;
use App\Service\Backup\Destination\S3Signer;
use App\Service\Backup\Destination\WebDavDestination;
use App\Support\SystemClock;
use App\Tests\Support\FakeDnsResolver;
use App\Tests\Support\LocalServer;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{string}>
     */
    public static function failingSteps(): array
    {
        return [
            'reading the marker back' => [DestinationException::STEP_READ],
            'comparing what came back' => [DestinationException::STEP_VERIFY],
            'deleting the marker' => [DestinationException::STEP_DELETE],
        ];
    }

    #[DataProvider('failingSteps')]
    public function testTestConnectionNamesTheStepThatFailed(string $failsAt): void
    {
        $destination = new class ($failsAt) implements BackupDestination {
            use RoundTripTest;

            private string $stored = '';

            public function __construct(private readonly string $failsAt)
            {
            }

            public function put(string $localPath, string $object, string $sha256, int $size): void
            {
                $this->stored = (string) file_get_contents($localPath);
            }

            public function head(string $object): ?int
            {
                return strlen($this->stored);
            }

            public function get(string $object, string $localPath): void
            {
                if ($this->failsAt === DestinationException::STEP_READ) {
                    // Raised under another label: the round trip names it.
                    throw new DestinationException(DestinationException::STEP_WRITE, 'unreachable', 'Gone.');
                }
                file_put_contents(
                    $localPath,
                    $this->failsAt === DestinationException::STEP_VERIFY ? 'something else' : $this->stored,
                );
            }

            public function delete(string $object): void
            {
                if ($this->failsAt === DestinationException::STEP_DELETE) {
                    throw new DestinationException(DestinationException::STEP_WRITE, 'denied', 'Not allowed.');
                }
            }
        };

        try {
            $destination->test();
            self::fail('A failing step passed the connection test.');
        } catch (DestinationException $exception) {
            self::assertSame($failsAt, $exception->step);
        }
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
        // Not left behind under a backup's name.
        self::assertFileDoesNotExist(self::$webdavRoot . '/renovo-instance-x.renovo-backup');
    }

    public function testAPrivateAddressIsRefusedUnlessItIsTrusted(): void
    {
        $destination = new WebDavDestination(
            $this->exchange([]),
            new RequestFactory(),
            new StreamFactory(),
            // https, so the scheme is no reason to refuse it: only the
            // private address behind the name is.
            str_replace('http://', 'https://', self::$webdav->url('/dav/', 'nas.test')),
            self::USER,
            self::PASSWORD,
        );

        try {
            $destination->test();
            self::fail('An untrusted private address was reached.');
        } catch (DestinationException $exception) {
            self::assertSame(DestinationException::STEP_CONNECT, $exception->step);
            self::assertSame('blocked', $exception->reason);
            self::assertStringContainsString('private', $exception->getMessage());
        }

        self::assertSame([], self::$webdav->requests());
    }

    public function testPlainHttpIsRefusedForAHostThatIsNotTrusted(): void
    {
        // A public address (TEST-NET-3, never routed), so only the scheme
        // can be the reason.
        $destination = new WebDavDestination(
            $this->exchange([], ['dav.example' => ['203.0.113.10']]),
            new RequestFactory(),
            new StreamFactory(),
            'http://dav.example/remote.php/dav/files/me/',
            self::USER,
            self::PASSWORD,
        );

        try {
            $destination->head('anything');
            self::fail('Plain http to an untrusted host was allowed.');
        } catch (DestinationException $exception) {
            self::assertSame(DestinationException::STEP_CONNECT, $exception->step);
            self::assertSame('blocked', $exception->reason);
            self::assertStringContainsString('http', $exception->getMessage());
        }
    }

    public function testARedirectFromADestinationIsRefusedNamingWhereItPointed(): void
    {
        $http = new LocalServer('http.php');
        try {
            $destination = new WebDavDestination(
                $this->exchange(['nas.test']),
                new RequestFactory(),
                new StreamFactory(),
                $http->url('/moved/', 'nas.test'),
                self::USER,
                self::PASSWORD,
            );
            $destination->get('renovo-instance-x.renovo-backup', $this->work . '/out');
            self::fail('A redirect was accepted.');
        } catch (DestinationException $exception) {
            self::assertSame('redirect', $exception->reason);
            self::assertStringContainsString('https://elsewhere.example/moved', $exception->getMessage());
            self::assertFileDoesNotExist($this->work . '/out');
        } finally {
            $http->stop();
        }
    }

    public function testWhatTheFarEndSaysIsMadePrintableAndShortBeforeItIsShown(): void
    {
        self::assertSame('https://x/?y', HttpExchange::printable("https://x/\x01y"));
        self::assertSame(str_repeat('a', 200) . '…', HttpExchange::printable(str_repeat('a', 300)));
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
        self::assertNull($this->s3()->head('phase39-tests/' . BackupDestination::MARKER));
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
            $make(true)->url('renovo/renovo-instance-a.renovo-backup'),
        );
        self::assertSame(
            'https://my-bucket.s3.eu-west-2.amazonaws.com/renovo/renovo-instance-a.renovo-backup',
            $make(false)->url('renovo/renovo-instance-a.renovo-backup'),
        );
    }

    public function testTestConnectionUsesItsOwnExchangeAndTheContainerGivesItOne(): void
    {
        $record = new BackupDestinationRecord(
            1,
            \App\Domain\BackupDestinationType::WebDav,
            'NAS',
            ['url' => self::$webdav->url('/dav/', 'nas.test'), 'username' => self::USER],
            ['password' => self::PASSWORD],
            false,
            true,
            new \App\Service\Backup\BackupRetention(7, 4, 6),
            null,
        );
        // The transfer exchange may not reach the NAS; the test exchange may.
        $factory = new BackupDestinationFactory(
            $this->exchange([]),
            new RequestFactory(),
            new StreamFactory(),
            new SystemClock(new \DateTimeZone('UTC')),
            $this->exchange(['nas.test']),
        );

        $factory->createForTest($record)->test();
        try {
            $factory->create($record)->test();
            self::fail('The transfer exchange was used for the test.');
        } catch (DestinationException $exception) {
            self::assertSame('blocked', $exception->reason);
        }

        $quick = new \ReflectionProperty(BackupDestinationFactory::class, 'quick');
        $container = (static function (): \Psr\Container\ContainerInterface {
            $settings = require dirname(__DIR__, 3) . '/config/settings.php';
            $builder = new \DI\ContainerBuilder();
            (require dirname(__DIR__, 3) . '/config/container.php')($builder, $settings);

            return $builder->build();
        })();
        self::assertNotNull($quick->getValue($container->get(BackupDestinationFactory::class)));
    }

    public function testAStoredS3KeyCarriesThePrefixOnceAndAChangedPrefixDoesNotMoveIt(): void
    {
        $record = static fn (string $prefix): BackupDestinationRecord => new BackupDestinationRecord(
            1,
            \App\Domain\BackupDestinationType::S3,
            'B2',
            ['endpoint' => 'https://s3.example.com', 'bucket' => 'b', 'prefix' => $prefix, 'path_style' => true],
            ['access_key_id' => 'a', 'secret_key' => 's'],
            false,
            true,
            new \App\Service\Backup\BackupRetention(7, 4, 6),
            null,
        );
        $factory = new \App\Service\Backup\Destination\BackupDestinationFactory(
            $this->exchange([]),
            new RequestFactory(),
            new StreamFactory(),
            new SystemClock(new \DateTimeZone('UTC')),
        );
        $file = 'renovo-instance-20270101-000000-abcdef.renovo-backup';
        $key = BackupDestinationFactory::objectKey($record('home/'), $file);

        self::assertSame('home/' . $file, $key);
        $now = $factory->create($record('home/'));
        self::assertInstanceOf(S3Destination::class, $now);
        self::assertSame('https://s3.example.com/b/home/' . $file, $now->url($key));
        $later = $factory->create($record('elsewhere/'));
        self::assertInstanceOf(S3Destination::class, $later);
        self::assertSame('https://s3.example.com/b/home/' . $file, $later->url($key));
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
            new HttpClient(new ResponseFactory(), new StreamFactory(), $options, OutboundPolicy::open()),
            new UrlGuard(
                $resolver,
                new StaticTrustedTargets([...$trusted, ...$this->literalTrust($trusted)]),
                $options,
                new NullLogger(),
                OutboundPolicy::open(),
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
