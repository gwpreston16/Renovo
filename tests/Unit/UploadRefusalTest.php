<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AttachmentStorage;
use App\Service\AvatarStorage;
use App\Service\ImportService;
use App\Service\LogoStorage;
use App\Service\ValidationException;
use App\Support\UploadRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Slim\Psr7\UploadedFile;

/**
 * A file over PHP's own upload limit is refused by each store with its "too
 * large" message, not "could not be uploaded. Try again.", which a retry
 * cannot fix. In the Docker image that is a file between 12 MB (PHP's file
 * limit) and 16 MB (the request limit); above that the web server answers.
 */
final class UploadRefusalTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function errors(): iterable
    {
        yield 'over upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, true];
        yield "over the form's MAX_FILE_SIZE" => [UPLOAD_ERR_FORM_SIZE, true];
        yield 'cut off part way' => [UPLOAD_ERR_PARTIAL, false];
        yield 'no file' => [UPLOAD_ERR_NO_FILE, false];
        yield 'received' => [UPLOAD_ERR_OK, false];
    }

    #[DataProvider('errors')]
    public function testOnlyASizeRefusalIsTooLarge(int $error, bool $tooLarge): void
    {
        self::assertSame($tooLarge, UploadRefusal::isTooLarge($this->upload($error)));
    }

    /**
     * @return iterable<string, array{callable(UploadedFile): mixed, string, string}>
     */
    public static function stores(): iterable
    {
        $dir = sys_get_temp_dir();
        yield 'attachment' => [
            static fn (UploadedFile $f): mixed => (new AttachmentStorage($dir, 10485760))->store($f, 1),
            'file',
            'error.attachment.too_large',
        ];
        yield 'logo' => [
            static fn (UploadedFile $f): mixed => (new LogoStorage($dir, 1048576))->store($f),
            'logo',
            'error.logo.too_large',
        ];
        yield 'avatar' => [
            static fn (UploadedFile $f): mixed => (new AvatarStorage($dir, 2097152))->store($f, 1),
            'avatar',
            'error.avatar.too_large',
        ];
        yield 'import' => [
            // stage() reads only the upload before refusing it, so the
            // service's collaborators are not needed.
            static fn (UploadedFile $f): mixed => (new ReflectionClass(ImportService::class))
                ->newInstanceWithoutConstructor()->stage($f),
            'file',
            'error.import.too_large',
        ];
    }

    /**
     * @param callable(UploadedFile): mixed $store
     */
    #[DataProvider('stores')]
    public function testEachStoreSaysAFileOverPhpsLimitIsTooLarge(callable $store, string $field, string $key): void
    {
        try {
            $store($this->upload(UPLOAD_ERR_INI_SIZE));
            self::fail('The upload was not refused.');
        } catch (ValidationException $exception) {
            self::assertSame($key, $exception->errors()[$field]->key ?? null);
        }
    }

    /**
     * @return iterable<string, array{int, string, int}>
     */
    public static function ceilings(): iterable
    {
        yield "PHP's limit above the app's (the image)" => [10485760, '12M', 10485760];
        yield "an UPLOAD_MAX raised past PHP's" => [52428800, '12M', 12582912];
        yield "a server left at PHP's 2M default" => [10485760, '2M', 2097152];
        yield 'kilobytes' => [1048576, '512K', 524288];
        yield 'plain bytes' => [1048576, '1000', 1000];
        yield 'gigabytes' => [1048576, '1G', 1048576];
        yield 'no limit of its own' => [1048576, '0', 1048576];
        yield 'not a size' => [1048576, 'lots', 1048576];
    }

    #[DataProvider('ceilings')]
    public function testTheCeilingIsTheSmallerOfTheTwoLimits(int $app, string $php, int $expected): void
    {
        self::assertSame($expected, UploadRefusal::ceiling($app, $php));
    }

    /**
     * An operator who raised the attachment limit to 50 MB and left PHP at
     * 12M must not tell a member with a 13 MB file "50 MB or smaller".
     */
    public function testTheMessageNamesTheLimitThatApplied(): void
    {
        // This PHP's own limit, whatever the machine running the test has.
        $limit = UploadRefusal::ceiling(52428800);

        try {
            (new AttachmentStorage(sys_get_temp_dir(), 52428800))->store($this->upload(UPLOAD_ERR_INI_SIZE), 1);
            self::fail('The upload was not refused.');
        } catch (ValidationException $exception) {
            $error = $exception->errors()['file'];
            self::assertSame(max(1, intdiv($limit, 1024 * 1024)), $error->parameters['megabytes']);
        }
    }

    public function testAnImportOverFiveMegabytesThatPhpTookIsTooLarge(): void
    {
        $import = (new ReflectionClass(ImportService::class))->newInstanceWithoutConstructor();

        try {
            $import->stage(new UploadedFile('', 'big.csv', 'text/csv', 5 * 1024 * 1024 + 1, UPLOAD_ERR_OK));
            self::fail('The import was not refused.');
        } catch (ValidationException $exception) {
            $error = $exception->errors()['file'];
            self::assertSame('error.import.too_large', $error->key);
            self::assertSame(['megabytes' => 5], $error->parameters);
        }
    }

    private function upload(int $error): UploadedFile
    {
        return new UploadedFile('', 'big.pdf', 'application/pdf', 0, $error);
    }
}
