<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\DashboardView;
use App\Domain\Density;
use App\Domain\LandingView;
use App\Domain\Palette;
use App\Domain\Theme;
use App\Domain\WeekStart;
use App\I18n\Locales;
use App\Repository\UserRepository;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * A user's own display preferences: theme, palette, language, week start, list
 * density and where they land — and their pay day (Phase 87), which budgets
 * switched to follow it read their periods from.
 *
 * Every value is validated here rather than in the controller, which is the
 * difference between a preference and an arbitrary string in a column: each
 * one resolves through its enum, and anything unrecognised becomes the
 * default instead of being stored and puzzled over later.
 *
 * None of these needs a permission. They change what one account sees and
 * nothing that anybody else does, so a Viewer may set them for the same reason
 * they may configure their own reminders.
 */
final class UserPreferencesService
{
    /** The earliest pay day taken: see payDay() for why 1900. */
    private const PAY_DAY_FLOOR = '1900-01-01';

    public function __construct(
        private readonly UserRepository $users,
        private readonly Locales $locales,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The theme on its own — the switch in the navigation bar, which must not
     * disturb any other preference.
     */
    public function updateTheme(int $userId, ?string $theme): Theme
    {
        $resolved = Theme::fromString($theme);

        $this->users->updatePreferences($userId, ['theme' => $resolved->value]);

        return $resolved;
    }

    /**
     * Which dashboard the account opens on, from the toggle at its top.
     *
     * Coerced rather than rejected, like the theme: the stored value only ever
     * chooses between two pages the member may already see, so an unknown one
     * is harmlessly Overview.
     */
    public function updateDashboardView(int $userId, ?string $view): DashboardView
    {
        $resolved = DashboardView::fromString($view);

        $this->users->updatePreferences($userId, ['dashboard_view' => $resolved->value]);

        return $resolved;
    }

    /**
     * List density on its own, from the toggle on the subscriptions toolbar.
     * Coerced like the theme: either value only changes padding.
     */
    public function updateDensity(int $userId, ?string $density): Density
    {
        $resolved = Density::fromString($density);

        $this->users->updatePreferences($userId, ['density' => $resolved->value]);

        return $resolved;
    }

    /**
     * The profile's appearance form, which saves on each change with script
     * and all at once without it.
     *
     * A field that was not submitted is left as it is. With script the form
     * posts on every change, and the language control is not drawn at all
     * while one catalogue exists; if absence meant "the default", either would
     * quietly reset a choice the member made.
     *
     * The palette is the one value *rejected* rather than coerced. Every other
     * preference here becomes its default when the value is not one it knows;
     * this one ends up in an attribute on every page's root element, so an
     * unknown value is a tampered form, and the answer to that is an error —
     * not a silent reset. It is refused after the rest are written, so a bad
     * palette does not throw away the theme or week start sent beside it.
     *
     * Returns what is now stored for the three values the page wears, so the
     * caller can restyle the page in place without a second read.
     *
     * @param array<string, mixed> $input
     * @return array{theme: ?string, palette: ?string, density: ?string}
     * @throws ValidationException
     */
    public function update(int $userId, array $input): array
    {
        $changes = [];

        if (array_key_exists('theme', $input)) {
            $changes['theme'] = Theme::fromString($this->string($input, 'theme'))->value;
        }
        if (array_key_exists('locale', $input)) {
            $changes['locale'] = $this->locale($this->string($input, 'locale'));
        }
        if (array_key_exists('week_start', $input)) {
            $changes['week_start'] = WeekStart::fromInt($this->int($input, 'week_start'))->value;
        }
        if (array_key_exists('density', $input)) {
            $changes['density'] = Density::fromString($this->string($input, 'density'))->value;
        }
        if (array_key_exists('landing_view', $input)) {
            $changes['landing_view'] = LandingView::fromString($this->string($input, 'landing_view'))->value;
        }

        // Refused with the palette's rule, after the rest are written: a pay
        // day that is not a real date, or more than a year ahead, is a mistake
        // to point out, not a value to guess at. Empty clears it.
        $payDayRefused = false;
        if (array_key_exists('pay_day', $input)) {
            $payDay = $this->payDay($this->string($input, 'pay_day') ?? '');
            if ($payDay === false) {
                $payDayRefused = true;
            } else {
                $changes['pay_day'] = $payDay?->format('Y-m-d');
            }
        }

        $palette = null;
        $paletteRefused = false;
        if (array_key_exists('palette', $input)) {
            $palette = Palette::tryFrom($this->string($input, 'palette') ?? '');
            if ($palette === null) {
                $paletteRefused = true;
            } else {
                $changes['palette'] = $palette->value;
            }
        }

        if ($changes !== []) {
            $this->users->updatePreferences($userId, $changes);
        }

        if ($paletteRefused) {
            throw ValidationException::field('palette', 'error.palette.unknown');
        }
        if ($payDayRefused) {
            throw ValidationException::field('pay_day', 'error.pay_day.invalid');
        }

        return [
            'theme' => $changes['theme'] ?? null,
            'palette' => $palette?->value,
            'density' => $changes['density'] ?? null,
        ];
    }

    /**
     * The first and last pay day the check below takes, as `Y-m-d`, for the
     * date input's `min` and `max`: from the same clock, so the date picker
     * offers no day the server then refuses (K240).
     *
     * @return array{min: string, max: string}
     */
    public function payDayRange(): array
    {
        return ['min' => self::PAY_DAY_FLOOR, 'max' => $this->latestPayDay()->format('Y-m-d')];
    }

    /**
     * A pay day as entered: null for none (empty), false for one that is not
     * a real date, is before 1900 or is more than a year ahead, else the date. Any day in the
     * past is fine — only its day of the month, or its place in a fortnightly
     * rhythm, is ever read.
     */
    private function payDay(string $raw): DateTimeImmutable|false|null
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return false;
        }

        // Year 1900 is a floor the engines can store: PostgreSQL has no year
        // 0 and MySQL's DATE starts at 1000, so either would be a server
        // error rather than this refusal.
        if ($date->format('Y-m-d') < self::PAY_DAY_FLOOR) {
            return false;
        }

        return $date > $this->latestPayDay() ? false : $date;
    }

    /**
     * A year after today, the last pay day a member may enter.
     */
    private function latestPayDay(): DateTimeImmutable
    {
        return $this->clock->today()->setTime(0, 0)->modify('+1 year');
    }

    /**
     * An empty locale is a real answer, not a missing one: it means "whatever
     * this instance is set to", which is what an account wants when the
     * operator changes the instance language.
     */
    private function locale(?string $locale): string
    {
        if ($locale === null || $locale === '' || !$this->locales->isAvailable($locale)) {
            return '';
        }

        return $locale;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function string(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function int(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
