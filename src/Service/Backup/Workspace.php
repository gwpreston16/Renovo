<?php

declare(strict_types=1);

namespace App\Service\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A private directory for an instance backup's plaintext while it exists
 * (Phase 38): mode 0700, removed with everything in it when the work ends.
 */
final class Workspace
{
    public static function create(string $parent, string $prefix): string
    {
        $directory = rtrim($parent, '/') . '/' . $prefix . bin2hex(random_bytes(8));
        if (!@mkdir($directory, 0o700)) {
            throw new BackupException('write_failed', 'A working directory for the backup could not be created.');
        }

        return $directory;
    }

    public static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
