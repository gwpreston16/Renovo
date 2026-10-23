<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\DashboardView;
use App\Domain\Density;
use App\Domain\LandingView;
use App\Domain\Palette;
use App\Domain\Theme;
use App\Domain\WeekStart;
use App\Support\Initials;
use DateTimeImmutable;

final class User
{
    /**
     * The domain a provisioned member with no mailbox is given an address on.
     *
     * `.invalid` is reserved by RFC 2606 and can never be delegated, so a
     * placeholder built on it cannot one day start delivering to a stranger.
     */
    public const UNREACHABLE_EMAIL_DOMAIN = '@no-mail.invalid';

    /**
     * The display preferences trail the rest with defaults deliberately: they
     * are additions to an entity that predates them, and every one of them has
     * a sensible value for an account that has never opened the settings page.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $passwordHash,
        public readonly bool $isInstanceAdmin,
        public readonly ?DateTimeImmutable $emailVerifiedAt,
        public readonly string $theme,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $webauthnHandle = null,
        /** Empty means "whatever this instance is configured for". */
        public readonly string $locale = '',
        public readonly int $weekStart = 1,
        public readonly string $density = 'comfortable',
        public readonly string $landingView = 'dashboard',
        /** Non-null means an administrator has revoked this account's login. */
        public readonly ?DateTimeImmutable $disabledAt = null,
        /** True while a member is still on the temporary password they were given. */
        public readonly bool $mustChangePassword = false,
        /** An address requested but not yet proved. `email` is still the login. */
        public readonly ?string $pendingEmail = null,
        public readonly ?string $avatarPath = null,
        /** Null means the default palette; see `palettePreference()`. */
        public readonly ?string $palette = null,
        /** Null means Overview; see `dashboardViewPreference()`. */
        public readonly ?string $dashboardView = null,
        /** The release whose update banner this administrator dismissed; see UpdateCheckService. */
        public readonly ?string $dismissedUpdateVersion = null,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function isDisabled(): bool
    {
        return $this->disabledAt !== null;
    }

    /**
     * Whether this account can receive mail at all.
     *
     * A member provisioned without an address of their own — the child case —
     * is given a placeholder on a domain reserved by RFC 2606 precisely so that
     * it can never resolve. The address is a login and nothing more, and
     * anything that would send to it has to ask first: a notification queued
     * for `@no-mail.invalid` is a bounce at best and a delivery to a domain
     * somebody later registers at worst.
     */
    public function canReceiveMail(): bool
    {
        return !str_ends_with($this->email, self::UNREACHABLE_EMAIL_DOMAIN);
    }

    public function initials(): string
    {
        return Initials::of($this->displayName);
    }

    /**
     * What a greeting calls this person: the display name up to its first
     * space. There is no separate first-name field, and a display name with
     * no space — a nickname, a single name — is used whole.
     */
    public function firstName(): string
    {
        $name = trim($this->displayName);
        $space = strpos($name, ' ');

        return $space === false ? $name : substr($name, 0, $space);
    }

    public function themePreference(): Theme
    {
        return Theme::fromString($this->theme);
    }

    public function palettePreference(): Palette
    {
        return Palette::fromNullable($this->palette);
    }

    public function densityPreference(): Density
    {
        return Density::fromString($this->density);
    }

    public function weekStartPreference(): WeekStart
    {
        return WeekStart::fromInt($this->weekStart);
    }

    public function landingViewPreference(): LandingView
    {
        return LandingView::fromString($this->landingView);
    }

    public function dashboardViewPreference(): DashboardView
    {
        return DashboardView::fromString($this->dashboardView);
    }
}
