<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/**
 * Which release this is, read from the `VERSION` file at the repository root.
 *
 * That file is the one place a version is typed. `package.json` and the newest
 * released `CHANGELOG.md` heading are kept in step with it by a test
 * (`VersionAgreementTest`), and `/release` writes all three.
 *
 * Read here, lazily, rather than through the settings array on purpose: the
 * compiled container keeps whatever settings it was built with (K4), and a
 * version frozen into it would go on naming the old release after an upgrade.
 * The build comes from the environment for the same reason.
 *
 * The build is a short commit id the image was given as `RENOVO_BUILD`. A
 * release image leaves it unset, and so does the Dockerfile's default: an
 * empty value is no build.
 */
final class AppVersion
{
    private ?string $current = null;

    public function __construct(
        private readonly string $versionFile,
        private readonly ?string $build = null,
    ) {
    }

    /**
     * The version as the running process found it: `VERSION` beside the code,
     * and `RENOVO_BUILD` from the environment.
     */
    public static function fromEnvironment(string $root): self
    {
        $build = getenv('RENOVO_BUILD');

        return new self($root . '/VERSION', is_string($build) ? $build : null);
    }

    public function current(): string
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $contents = is_file($this->versionFile) ? file_get_contents($this->versionFile) : false;
        $version = is_string($contents) ? trim($contents) : '';
        if ($version === '') {
            throw new RuntimeException(sprintf('The version file %s is missing or empty.', $this->versionFile));
        }

        return $this->current = $version;
    }

    public function build(): ?string
    {
        $build = trim($this->build ?? '');

        return $build === '' ? null : $build;
    }

    /**
     * "1.7.0", or "1.7.0 (a1b2c3d)" for an image built from a commit.
     */
    public function label(): string
    {
        $build = $this->build();

        return $build === null ? $this->current() : sprintf('%s (%s)', $this->current(), $build);
    }
}
