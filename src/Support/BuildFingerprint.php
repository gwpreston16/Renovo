<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A short name for the code that is running, so compiled caches (the DI
 * container, Twig templates) are never reused by different code.
 *
 * The caches live on the persistent `var/` volume. Before this, a new image
 * found the previous release's compiled container still there and used it,
 * and a service whose constructor had changed failed on every request. The
 * fingerprint changes with the release (`VERSION`) and whenever the
 * autoloader is regenerated, which every image build and every
 * `composer install` does, so new code compiles into a directory of its own.
 */
final class BuildFingerprint
{
    public static function of(string $root): string
    {
        $version = is_file($root . '/VERSION') ? (string) file_get_contents($root . '/VERSION') : '';
        $autoload = $root . '/vendor/composer/autoload_static.php';
        $built = is_file($autoload) ? (string) filemtime($autoload) : '';

        return substr(sha1(trim($version) . '|' . $built), 0, 12);
    }

    /**
     * Remove the cache directories other builds left beside this one.
     */
    public static function pruneSiblings(string $directory): void
    {
        $parent = dirname($directory);
        foreach (glob($parent . '/*', GLOB_ONLYDIR) ?: [] as $sibling) {
            if ($sibling !== $directory) {
                self::remove($sibling);
            }
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
