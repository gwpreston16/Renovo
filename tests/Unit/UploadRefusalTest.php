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

    private function upload(int $error): UploadedFile
    {
        return new UploadedFile('', 'big.pdf', 'application/pdf', 0, $error);
    }
}
