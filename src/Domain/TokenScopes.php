<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The scopes one token holds, stored space-separated as OAuth writes them.
 *
 * Always in declaration order and without repeats, so the same set is always
 * the same string — in the database, in the audit entry for its issue and in
 * `GET /me`. A name that is not a scope is dropped when reading: a token never
 * gains access from a value nobody can explain.
 */
final class TokenScopes
{
    public const PRESET_READ = 'read';
    public const PRESET_WRITE = 'write';
    public const PRESET_CALENDAR = 'calendar';
    public const PRESET_CUSTOM = 'custom';

    /** The presets the issue form offers, in the order it offers them. */
    public const PRESETS = [self::PRESET_READ, self::PRESET_WRITE, self::PRESET_CALENDAR, self::PRESET_CUSTOM];

    /** @var list<TokenScope> */
    private readonly array $scopes;

    /**
     * @param iterable<TokenScope> $scopes
     */
    private function __construct(iterable $scopes)
    {
        $held = [];
        foreach ($scopes as $scope) {
            $held[$scope->value] = true;
        }

        $this->scopes = array_values(array_filter(
            TokenScope::cases(),
            static fn (TokenScope $scope): bool => isset($held[$scope->value]),
        ));
    }

    /**
     * @param iterable<TokenScope> $scopes
     */
    public static function of(iterable $scopes): self
    {
        return new self($scopes);
    }

    public static function fromString(?string $value): self
    {
        $scopes = [];
        foreach (preg_split('/\s+/', trim((string) $value)) ?: [] as $name) {
            $scope = TokenScope::tryFrom($name);
            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return new self($scopes);
    }

    /**
     * @param list<string> $values
     */
    public static function fromValues(array $values): self
    {
        return self::fromString(implode(' ', $values));
    }

    /** Every read scope, and the calendar. */
    public static function readEverything(): self
    {
        return new self(array_filter(TokenScope::cases(), static fn (TokenScope $s): bool => !$s->isWrite()));
    }

    public static function everything(): self
    {
        return new self(TokenScope::cases());
    }

    public static function calendarOnly(): self
    {
        return new self([TokenScope::CalendarRead]);
    }

    /**
     * A named preset's scopes, or null for Custom and anything unknown.
     */
    public static function preset(string $name): ?self
    {
        return match ($name) {
            self::PRESET_READ => self::readEverything(),
            self::PRESET_WRITE => self::everything(),
            self::PRESET_CALENDAR => self::calendarOnly(),
            default => null,
        };
    }

    /**
     * Which preset this set is, or Custom when it is none of them.
     */
    public function presetName(): string
    {
        foreach ([self::PRESET_READ, self::PRESET_WRITE, self::PRESET_CALENDAR] as $name) {
            if (self::preset($name)?->toString() === $this->toString()) {
                return $name;
            }
        }

        return self::PRESET_CUSTOM;
    }

    public function allows(TokenScope $needed): bool
    {
        foreach ($this->scopes as $scope) {
            if ($scope->satisfies($needed)) {
                return true;
            }
        }

        return false;
    }

    public function has(TokenScope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function allowsAnyWrite(): bool
    {
        foreach ($this->scopes as $scope) {
            if ($scope->isWrite()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only the calendar: the feed's own kind of token.
     */
    public function isCalendarOnly(): bool
    {
        return $this->scopes === [TokenScope::CalendarRead];
    }

    public function isEmpty(): bool
    {
        return $this->scopes === [];
    }

    /**
     * @return list<TokenScope>
     */
    public function all(): array
    {
        return $this->scopes;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(static fn (TokenScope $scope): string => $scope->value, $this->scopes);
    }

    public function toString(): string
    {
        return implode(' ', $this->values());
    }
}
