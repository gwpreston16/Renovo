<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Repository\CategoryRepository;
use App\Security\Scope;
use App\Service\Import\ImportField;
use App\Service\Import\ImportPreset;
use App\Service\Import\RowTranslator;
use App\Service\Import\SourceFile;
use App\Support\Clock;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Importing subscriptions from a CSV or JSON file.
 *
 * Four steps, and the order is the design: upload, map the columns, preview
 * what will happen, then commit. The preview is not decoration. Every other
 * tracker's export uses different column names and different words for a
 * billing cycle, so an importer that went straight from file to database would
 * be guessing on the user's behalf and telling them afterwards.
 *
 * Between steps the file sits in a staging directory under a random id, and
 * that id is held in the session. It is not in the URL and not in a form field:
 * an id that travelled through the browser would let one user step through
 * another user's upload. Re-reading and re-parsing the file at each step costs
 * nothing at these sizes and means there is no half-parsed state to keep
 * consistent.
 *
 * Nothing here writes outside the scoping layer — rows are created by
 * `SubscriptionService::create()`, exactly as the form creates them, so an
 * imported subscription lands in the importer's household with the same owner
 * rules and the same price-history row as one typed in by hand.
 */
final class ImportService
{
    /**
     * Comfortably more than 2000 rows of subscription data, and far less than
     * anything that would trouble the parser.
     */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * How long a staged upload survives. Long enough to map a wide file
     * carefully, short enough that an abandoned one does not sit on disk.
     */
    private const STALE_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly CategoryRepository $categories,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
        private readonly string $directory,
    ) {
    }

    /**
     * Take an upload into staging.
     *
     * @return string The staging id, to be held in the session.
     * @throws ValidationException
     */
    public function stage(UploadedFileInterface $file): string
    {
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::field('file', 'error.import.file_required');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationException::field('file', 'error.upload.failed');
        }

        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_BYTES) {
            throw ValidationException::field(
                'file',
                'error.import.too_large',
                ['megabytes' => intdiv(self::MAX_BYTES, 1024 * 1024)],
            );
        }

        $this->ensureDirectory();
        $this->purgeStale();

        $id = bin2hex(random_bytes(16));
        $file->moveTo($this->dataPath($id));

        if ((int) filesize($this->dataPath($id)) > self::MAX_BYTES) {
            @unlink($this->dataPath($id));

            throw ValidationException::field('file', 'error.import.too_large_absolute');
        }

        $name = (string) $file->getClientFilename();
        file_put_contents($this->metaPath($id), json_encode([
            'name' => $name,
            'staged_at' => $this->clock->now()->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));

        // Parse once now so an unreadable file is reported on the screen that
        // uploaded it rather than on the mapping screen.
        $this->read($id);

        return $id;
    }

    /**
     * @throws ValidationException
     */
    public function read(string $id): SourceFile
    {
        $path = $this->dataPath($id);
        if (!$this->isValidId($id) || !is_file($path)) {
            throw ValidationException::field('file', 'error.import.expired');
        }

        return SourceFile::parse($path, $this->originalName($id));
    }

    public function originalName(string $id): string
    {
        $meta = @file_get_contents($this->metaPath($id));
        if ($meta === false) {
            return 'import.csv';
        }

        $decoded = json_decode($meta, true);

        return is_array($decoded) && is_string($decoded['name'] ?? null) && $decoded['name'] !== ''
            ? $decoded['name']
            : 'import.csv';
    }

    /**
     * The mapping the presets suggest for a file's headers.
     *
     * @param list<string> $headers
     * @return array<string, string>
     */
    public function suggestMapping(string $preset, array $headers): array
    {
        return ImportPreset::guess($preset, $headers);
    }

    /**
     * Translate and validate every row without writing anything.
     *
     * @param array<string, string> $mapping
     * @return list<array{
     *     row: int,
     *     input: array<string, mixed>,
     *     category: string,
     *     errors: array<string, \App\Service\ValidationError>
     * }>
     * @throws ValidationException
     */
    public function preview(Scope $scope, string $id, array $mapping, string $defaultCurrency): array
    {
        $file = $this->read($id);
        $translator = new RowTranslator($this->cleanMapping($mapping, $file->headers), $defaultCurrency);

        $preview = [];
        foreach ($file->rows as $index => $row) {
            $input = $translator->translate($row);

            $preview[] = [
                'row' => $index + 1,
                'input' => $input,
                'category' => $translator->categoryName($row),
                // The real rules, not a copy of them.
                'errors' => $this->subscriptions->validationErrors($scope, $input),
            ];
        }

        return $preview;
    }

    /**
     * Write the valid rows.
     *
     * Invalid rows are skipped rather than aborting the run, and the count of
     * each is reported. A forty-row file with one bad date should import
     * thirty-nine rows and say so — the preview has already shown exactly which
     * one will be left behind, so this is not a surprise, and refusing the lot
     * would mean hand-editing a file to rescue it.
     *
     * @param array<string, string> $mapping
     * @return array{imported: int, skipped: int}
     * @throws ValidationException
     */
    public function commit(Scope $scope, User $actor, string $id, array $mapping, string $defaultCurrency): array
    {
        $file = $this->read($id);
        $translator = new RowTranslator($this->cleanMapping($mapping, $file->headers), $defaultCurrency);

        $imported = 0;
        $skipped = 0;

        foreach ($file->rows as $row) {
            $input = $translator->translate($row);

            $categoryId = $this->resolveCategory($scope, $translator->categoryName($row));
            if ($categoryId !== null) {
                $input['category_id'] = $categoryId;
            }

            try {
                $this->subscriptions->create($scope, $input);
                $imported++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        $this->audit->record(AuditAction::DataImported, $actor, [
            'file' => $this->originalName($id),
            'imported' => $imported,
            'skipped' => $skipped,
        ], $scope->householdId);

        $this->discard($id);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    public function discard(string $id): void
    {
        if (!$this->isValidId($id)) {
            return;
        }

        @unlink($this->dataPath($id));
        @unlink($this->metaPath($id));
    }

    /**
     * Remove staged uploads nobody came back for.
     *
     * Called when a new upload is staged and by the scheduler, so an abandoned
     * import does not leave a file on disk indefinitely.
     */
    public function purgeStale(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $cutoff = $this->clock->now()->getTimestamp() - self::STALE_AFTER_SECONDS;
        $removed = 0;

        foreach ((array) glob(rtrim($this->directory, '/') . '/*') as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }

            if ((int) filemtime($path) < $cutoff) {
                @unlink($path);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Find or create the category a row names.
     *
     * Creating one is the right default: a file that says "Streaming" and an
     * import that silently dropped it would lose information the user can see
     * in their own spreadsheet. A category is a household-wide label with no
     * financial content, so inventing one costs nothing.
     */
    private function resolveCategory(Scope $scope, string $name): ?int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            return null;
        }

        $existing = $this->categories->findByName($scope, $name);
        if ($existing !== null) {
            return $existing->id;
        }

        return $this->categories->create($scope, $name, null);
    }

    /**
     * Keep only mappings that name a real field and a header the file has.
     *
     * The mapping arrives from a form, so both halves are user input.
     *
     * @param array<string, string> $mapping
     * @param list<string> $headers
     * @return array<string, string>
     */
    private function cleanMapping(array $mapping, array $headers): array
    {
        $clean = [];

        foreach ($mapping as $field => $header) {
            if (ImportField::tryFrom((string) $field) === null) {
                continue;
            }

            if (!is_string($header) || !in_array($header, $headers, true)) {
                continue;
            }

            $clean[(string) $field] = $header;
        }

        return $clean;
    }

    private function isValidId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    private function dataPath(string $id): string
    {
        return rtrim($this->directory, '/') . '/' . $id . '.upload';
    }

    private function metaPath(string $id): string
    {
        return rtrim($this->directory, '/') . '/' . $id . '.meta';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Import directory "%s" is not writable.', $this->directory));
        }
    }
}
