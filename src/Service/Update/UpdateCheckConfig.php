<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * What the operator's environment says about update checks.
 *
 * `UPDATE_CHECK=false` turns the feature off whatever an administrator has
 * chosen, for an operator who manages updates elsewhere or runs with no route
 * out. `UPDATE_CHECK_REPOSITORY` names the `owner/name` whose releases are
 * checked, so a fork can follow its own.
 *
 * Read from the environment when the service is built, not through the
 * settings array: the compiled container keeps the settings it was compiled
 * with (K4), and a switch an operator turned off must not be ignored until
 * somebody clears a cache.
 */
final class UpdateCheckConfig
{
    public const DEFAULT_REPOSITORY = 'gwpreston16/Renovo';

    private const REPOSITORY_PATTERN = '/^[A-Za-z0-9-]+\/[A-Za-z0-9._-]+$/';

    public function __construct(
        public readonly bool $allowedByEnvironment = true,
        public readonly string $repository = self::DEFAULT_REPOSITORY,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $read = static function (string $key): string {
            $value = $_ENV[$key] ?? getenv($key);

            return is_string($value) ? trim($value) : '';
        };

        $flag = strtolower($read('UPDATE_CHECK'));
        $repository = $read('UPDATE_CHECK_REPOSITORY');

        return new self(
            !in_array($flag, ['0', 'false', 'no', 'off'], true),
            $repository === '' ? self::DEFAULT_REPOSITORY : $repository,
        );
    }

    public function repositoryIsValid(): bool
    {
        return preg_match(self::REPOSITORY_PATTERN, $this->repository) === 1;
    }

    /**
     * Why the environment rules checks out, or null when it does not.
     */
    public function unavailableReason(): ?UpdateUnavailable
    {
        if (!$this->allowedByEnvironment) {
            return UpdateUnavailable::DisabledByEnvironment;
        }

        return $this->repositoryIsValid() ? null : UpdateUnavailable::InvalidRepository;
    }

    public function latestReleaseUrl(): string
    {
        return sprintf('https://api.github.com/repos/%s/releases/latest', $this->repository);
    }
}
