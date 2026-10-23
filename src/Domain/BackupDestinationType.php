<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The kinds of place an instance backup can be sent (Phase 39). A new kind
 * is a case here, a `BackupDestination` implementation, and its fields in
 * `BackupDestinationService`.
 */
enum BackupDestinationType: string
{
    case S3 = 's3';
    case WebDav = 'webdav';
    case Directory = 'directory';

    public function labelKey(): string
    {
        return 'backup.destination.type.' . $this->value;
    }

    /**
     * Its name in `assets/theme/icons.json`.
     */
    public function icon(): string
    {
        return 'destination-' . $this->value;
    }

    /**
     * The form's fields, in order. A field's label and hint are
     * `backup.destination.field.<name>` and `….<name>_hint`.
     *
     * @return list<array{name: string, input: string, secret: bool, hint: bool, required: bool}>
     */
    public function fields(): array
    {
        $field = static fn (string $name, string $input = 'text', bool $hint = false): array => [
            'name' => $name,
            'input' => $input,
            'secret' => in_array($name, ['access_key_id', 'secret_key', 'password'], true),
            'hint' => $hint,
            'required' => !in_array($name, ['region', 'prefix', 'path_style'], true),
        ];

        return match ($this) {
            self::S3 => [
                $field('endpoint', 'url', true),
                $field('region'),
                $field('bucket'),
                $field('prefix', 'text', true),
                $field('path_style', 'checkbox', true),
                $field('access_key_id', 'password'),
                $field('secret_key', 'password'),
            ],
            self::WebDav => [
                $field('url', 'url', true),
                $field('username'),
                $field('password', 'password'),
            ],
            self::Directory => [
                $field('path', 'text', true),
            ],
        };
    }

    /**
     * The settings that are credentials: stored encrypted, never shown again.
     *
     * @return list<string>
     */
    public function secretFields(): array
    {
        return match ($this) {
            self::S3 => ['access_key_id', 'secret_key'],
            self::WebDav => ['password'],
            self::Directory => [],
        };
    }
}
