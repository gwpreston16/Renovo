<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\AuditAction;
use App\Domain\BackupDestinationType;
use App\Domain\Entity\BackupDestinationRecord;
use App\Domain\Entity\User;
use App\Repository\BackupDestinationRepository;
use App\Service\AuditLogService;
use App\Service\Backup\Destination\BackupDestinationFactory;
use App\Service\Backup\Destination\DestinationException;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;

/**
 * Adding, changing, testing and removing backup destinations (Phase 39).
 *
 * Credentials are written once and never shown again: an edit that leaves a
 * credential field blank keeps what is stored, and the form says only that
 * one is set. Removing a destination removes only Renovo's record of it —
 * what it holds stays where it is.
 *
 * Instance administrators only, at every route that reaches this.
 */
final class BackupDestinationService
{
    private const NAME_MAX = 100;
    private const FIELD_MAX = 500;

    /**
     * @param list<string> $protectedPaths Directories a Directory destination
     *        may not be inside: the application, its var/ and BACKUP_DIRECTORY.
     */
    public function __construct(
        private readonly BackupDestinationRepository $destinations,
        private readonly BackupDestinationFactory $factory,
        private readonly InstanceSettingsService $settings,
        private readonly AuditLogService $audit,
        private readonly array $protectedPaths,
    ) {
    }

    /**
     * @return list<BackupDestinationRecord>
     */
    public function all(): array
    {
        return $this->destinations->all();
    }

    public function find(int $id): ?BackupDestinationRecord
    {
        return $this->destinations->find($id);
    }

    public function hasEnabled(): bool
    {
        return $this->destinations->count(true) > 0;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(User $actor, array $input): int
    {
        $type = BackupDestinationType::tryFrom($this->str($input, 'type'));
        if ($type === null) {
            throw ValidationException::field('type', 'error.backup_destination.type');
        }

        [$name, $config, $secrets] = $this->validate($type, $input, null);
        $id = $this->destinations->create(
            $type,
            $name,
            $config,
            $secrets,
            true,
            $this->retention($input, $this->settings->backupRetention()),
        );

        $this->audit->record(AuditAction::BackupDestinationAdded, $actor, [
            'destination' => $id,
            'type' => $type->value,
            'name' => $name,
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(User $actor, int $id, array $input): void
    {
        $existing = $this->destinations->find($id) ?? throw ValidationException::field(
            'type',
            'error.backup_destination.missing',
        );

        [$name, $config, $secrets] = $this->validate($existing->type, $input, $existing);
        $this->destinations->update($id, $name, $config, $secrets, $this->retention($input, $existing->retention));

        $this->audit->record(AuditAction::BackupDestinationChanged, $actor, [
            'destination' => $id,
            'name' => $name,
            'credentials_replaced' => $secrets !== null,
        ]);
    }

    public function setEnabled(User $actor, int $id, bool $enabled): bool
    {
        $existing = $this->destinations->find($id);
        if ($existing === null) {
            return false;
        }

        if ($existing->enabled !== $enabled) {
            $this->destinations->setEnabled($id, $enabled);
            $this->audit->record(AuditAction::BackupDestinationChanged, $actor, [
                'destination' => $id,
                'name' => $existing->name,
                'enabled' => $enabled,
            ]);
        }

        return true;
    }

    /**
     * Forget a destination. Nothing stored there is deleted.
     */
    public function remove(User $actor, int $id): bool
    {
        $existing = $this->destinations->find($id);
        if ($existing === null) {
            return false;
        }

        $this->destinations->delete($id);
        $this->audit->record(AuditAction::BackupDestinationRemoved, $actor, [
            'destination' => $id,
            'type' => $existing->type->value,
            'name' => $existing->name,
        ]);

        return true;
    }

    /**
     * Write, read back and delete a marker.
     *
     * @return DestinationException|null Null when every step passed.
     */
    public function test(int $id): ?DestinationException
    {
        $record = $this->destinations->find($id);
        if ($record === null) {
            return new DestinationException(DestinationException::STEP_CONNECT, 'missing', 'No such destination.');
        }

        try {
            $this->factory->createForTest($record)->test();
        } catch (DestinationException $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * What a failed add hands back to its form: the kind, what was typed but
     * no credential, and each field's error.
     *
     * @param array<string, mixed> $input
     * @return array{type: string, values: array<string, string>, errors: array<string, string>}
     */
    public static function failedForm(array $input, ValidationException $exception): array
    {
        $type = BackupDestinationType::tryFrom(is_string($input['type'] ?? null) ? $input['type'] : '');
        $values = [];
        foreach ($type?->fields() ?? [] as $field) {
            $value = $input[$field['name']] ?? '';
            if (!$field['secret'] && is_string($value)) {
                $values[$field['name']] = mb_substr($value, 0, self::FIELD_MAX);
            }
        }
        foreach (['name', 'keep_daily', 'keep_weekly', 'keep_monthly'] as $field) {
            $value = $input[$field] ?? '';
            $values[$field] = is_string($value) ? mb_substr($value, 0, self::NAME_MAX) : '';
        }

        $errors = [];
        foreach ($exception->errors() as $field => $error) {
            $errors[$field] = $error->key;
        }

        return ['type' => $type === null ? '' : $type->value, 'values' => $values, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{string, array<string, string|bool>, array<string, string>|null}
     *         The name, the settings, and the credentials — null when an edit
     *         keeps the stored ones.
     * @throws ValidationException
     */
    private function validate(BackupDestinationType $type, array $input, ?BackupDestinationRecord $existing): array
    {
        $errors = [];

        $name = $this->str($input, 'name');
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'error.backup_destination.name';
        }

        $config = [];
        switch ($type) {
            case BackupDestinationType::S3:
                $config['endpoint'] = rtrim($this->str($input, 'endpoint'), '/');
                if (!$this->isHttpUrl($config['endpoint'], false)) {
                    $errors['endpoint'] = 'error.backup_destination.endpoint';
                }
                $config['region'] = $this->str($input, 'region');
                if ($config['region'] !== '' && preg_match('/^[a-z0-9-]{1,64}$/', $config['region']) !== 1) {
                    $errors['region'] = 'error.backup_destination.region';
                }
                $config['bucket'] = $this->str($input, 'bucket');
                if (preg_match('/^[a-z0-9][a-z0-9.\-_]{1,62}$/i', $config['bucket']) !== 1) {
                    $errors['bucket'] = 'error.backup_destination.bucket';
                }
                $config['prefix'] = ltrim($this->str($input, 'prefix'), '/');
                if (
                    strlen($config['prefix']) > 200
                    || preg_match('#^[A-Za-z0-9!_.*\'()/-]*$#', $config['prefix']) !== 1
                    || str_contains($config['prefix'], '..')
                ) {
                    $errors['prefix'] = 'error.backup_destination.prefix';
                }
                $config['path_style'] = $this->str($input, 'path_style') === '1';
                break;
            case BackupDestinationType::WebDav:
                $url = $this->str($input, 'url');
                $config['url'] = str_ends_with($url, '/') ? $url : $url . '/';
                if (!$this->isHttpUrl($url, true)) {
                    $errors['url'] = 'error.backup_destination.url';
                }
                $config['username'] = $this->str($input, 'username');
                if ($config['username'] === '' || str_contains($config['username'], ':')) {
                    $errors['username'] = 'error.backup_destination.username';
                }
                break;
            case BackupDestinationType::Directory:
                $config['path'] = rtrim($this->str($input, 'path'), '/');
                $problem = $this->directoryProblem($config['path']);
                if ($problem !== null) {
                    $errors['path'] = $problem;
                }
                break;
        }

        // Credentials: every field on a new destination; on an edit, blank
        // means "keep", and any one given replaces the set.
        $secrets = [];
        $given = false;
        foreach ($type->secretFields() as $field) {
            $secrets[$field] = $this->str($input, $field, false);
            $given = $given || $secrets[$field] !== '';
        }
        // Kept only while they still go where they were entered for: stored
        // credentials are never sent to a new address, or an edit could
        // read them back at a server of the editor's choosing.
        $moved = $existing !== null && $this->targetChanged($type, $config, $existing);
        if ($existing !== null && !$given && !$moved) {
            $secrets = null;
        } elseif ($existing !== null && !$moved && $existing->secretsUnreadable === false) {
            foreach ($secrets as $field => $value) {
                $secrets[$field] = $value !== '' ? $value : ($existing->secrets[$field] ?? '');
            }
        }
        foreach ($secrets ?? [] as $field => $value) {
            if ($value === '' || strlen($value) > self::FIELD_MAX) {
                $errors[$field] = $moved && !$given
                    ? 'error.backup_destination.credential_moved'
                    : 'error.backup_destination.credential';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$name, $config, $secrets];
    }

    /**
     * Whether an edit points the destination somewhere else: another server,
     * bucket, region or account. A different prefix or path within the same
     * place is not.
     *
     * @param array<string, string|bool> $config
     */
    private function targetChanged(BackupDestinationType $type, array $config, BackupDestinationRecord $existing): bool
    {
        $fields = match ($type) {
            BackupDestinationType::S3 => ['endpoint', 'region', 'bucket', 'path_style'],
            BackupDestinationType::WebDav => ['url', 'username'],
            BackupDestinationType::Directory => [],
        };

        foreach ($fields as $field) {
            if (($config[$field] ?? '') !== ($existing->config[$field] ?? ($field === 'path_style' ? false : ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The retention counts from the form, clamped as the local policy is.
     *
     * @param array<string, mixed> $input
     */
    private function retention(array $input, BackupRetention $fallback): BackupRetention
    {
        $count = function (string $key, int $default) use ($input): int {
            $value = $this->str($input, $key);

            return $value === '' || !ctype_digit($value)
                ? $default
                : max(0, min(BackupRetention::MAX, (int) $value));
        };

        return new BackupRetention(
            $count('keep_daily', $fallback->daily),
            $count('keep_weekly', $fallback->weekly),
            $count('keep_monthly', $fallback->monthly),
        );
    }

    /**
     * Https, or http — which the guard then allows only to a trusted host. No
     * credentials in the URL: they have their own fields.
     */
    private function isHttpUrl(string $url, bool $allowPath): bool
    {
        if (strlen($url) > self::FIELD_MAX || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && ($allowPath || in_array($parts['path'] ?? '', ['', '/'], true));
    }

    /**
     * Absolute, there, writable, and not anywhere Renovo itself keeps
     * things — where its own pruning, or a web server, would reach the copies.
     */
    private function directoryProblem(string $path): ?string
    {
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_contains($path, "\0")
            || strlen($path) > self::FIELD_MAX
        ) {
            return 'error.backup_destination.path';
        }

        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return 'error.backup_destination.path_missing';
        }
        if (!is_writable($real)) {
            return 'error.backup_destination.path_unwritable';
        }

        foreach ($this->protectedPaths as $protected) {
            $inside = realpath($protected);
            $inside = $inside === false ? rtrim($protected, '/') : $inside;
            if ($inside === '') {
                continue;
            }
            // Inside one, or holding one: either way the two would mix.
            if (str_starts_with($real . '/', $inside . '/') || str_starts_with($inside . '/', $real . '/')) {
                return 'error.backup_destination.path_inside';
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key, bool $trim = true): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return $trim ? trim($value) : $value;
    }
}
