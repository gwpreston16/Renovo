<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Domain\AlertType;
use App\Domain\AuditAction;
use App\Domain\DigestMode;
use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\NotificationPreferences;
use App\Domain\Entity\User;
use App\Notification\NotifierRegistry;
use App\Repository\NotificationChannelRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\NotificationRouteRepository;
use App\Service\AuditLogService;
use App\Service\ValidationError;
use App\Service\ValidationException;

/**
 * A user's channels, preferences and routing.
 *
 * Everything here is scoped to one account by its user id — a channel is
 * personal, and the household isolation mode has nothing to say about it. What
 * the service adds over the three repositories is the rules that connect them:
 *
 *  - a channel's configuration is validated by the notifier that will use it,
 *    so the settings form never has to know what a Gotify token looks like;
 *  - a secret left blank keeps its stored value, because the form deliberately
 *    cannot show it;
 *  - **no routing rows means everything goes everywhere.** This is the default
 *    a new channel lands in, and it is the difference between adding a channel
 *    and having it work, and adding a channel and wondering why it is silent.
 */
final class NotificationSettingsService
{
    private const MAX_LEAD_TIMES = 6;
    private const MAX_LEAD_DAYS = 365;

    public function __construct(
        private readonly NotificationChannelRepository $channels,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly NotificationRouteRepository $routes,
        private readonly NotifierRegistry $notifiers,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @return list<NotificationChannel>
     */
    public function channels(int $userId, bool $activeOnly = false): array
    {
        return $this->channels->findAllForUser($userId, $activeOnly);
    }

    public function channel(int $userId, int $id): ?NotificationChannel
    {
        return $this->channels->find($userId, $id);
    }

    /**
     * @param array<string, string> $input
     * @throws ValidationException
     */
    public function createChannel(User $user, array $input): int
    {
        $userId = $user->id;
        $type = trim($input['channel_type'] ?? '');
        $notifier = $this->notifiers->find($type);

        if ($notifier === null) {
            throw ValidationException::field('channel_type', 'error.channel.type_required');
        }

        $label = $this->label($input, $notifier->label());
        $config = $notifier->normaliseConfig($input);

        $id = $this->channels->create($userId, $notifier->key(), $label, $config, $this->isActive($input));
        $this->audited(AuditAction::NotificationChannelCreated, $user, $notifier->key(), $label);

        // With no routing at all, every channel hears everything and a new one
        // needs nothing. Once a member has saved routing, though, a channel
        // with no rows is one they have muted — so a channel added after that
        // is given every alert type explicitly, or adding it would be adding
        // a channel that never speaks.
        $routes = $this->routes->findForUser($userId);
        if ($routes !== []) {
            $typed = [];
            foreach ($routes as $channelId => $types) {
                $typed[$channelId] = array_values(array_filter(array_map(AlertType::tryFromString(...), $types)));
            }
            $typed[$id] = AlertType::forUser($user->isInstanceAdmin);

            $this->routes->replaceForUser($userId, $typed);
        }

        return $id;
    }

    /**
     * @param array<string, string> $input
     * @throws ValidationException
     */
    public function updateChannel(User $user, int $id, array $input): void
    {
        $userId = $user->id;
        $existing = $this->channels->find($userId, $id);
        if ($existing === null) {
            throw ValidationException::field('channel_type', 'error.channel.missing');
        }

        // Merging the form into an empty config would save it over ciphertext
        // the instance merely cannot open yet, losing the secrets for good.
        if ($existing->configUnreadable) {
            throw ValidationException::field('channel_type', 'error.channel.unreadable');
        }

        $notifier = $this->notifiers->find($existing->type);
        if ($notifier === null) {
            throw ValidationException::field('channel_type', 'error.channel.type_unavailable');
        }

        $config = $notifier->normaliseConfig($input, $existing->config);
        $label = $this->label($input, $existing->label);

        $this->channels->update($userId, $id, $label, $config, $this->isActive($input));
        $this->audited(AuditAction::NotificationChannelUpdated, $user, $existing->type, $label);
    }

    /**
     * Turn a channel on or off and change nothing else about it — the
     * Notifications page's switch. Only the flag is written, so a secret is
     * never round-tripped through a form, and a config this instance cannot
     * decrypt is never overwritten.
     *
     * @throws ValidationException when the channel is not this user's.
     */
    public function setChannelActive(User $user, int $id, bool $active): void
    {
        $existing = $this->channels->find($user->id, $id);
        if ($existing === null) {
            throw ValidationException::field('channel_type', 'error.channel.missing');
        }

        $this->channels->setActive($user->id, $id, $active);
        $this->audited(AuditAction::NotificationChannelUpdated, $user, $existing->type, $existing->label);
    }

    public function deleteChannel(User $user, int $id): void
    {
        $existing = $this->channels->find($user->id, $id);
        if ($existing === null) {
            return;
        }

        $this->channels->delete($user->id, $id);
        $this->audited(AuditAction::NotificationChannelDeleted, $user, $existing->type, $existing->label);
    }

    /**
     * A channel change in the audit log: its type and label, never its config,
     * which holds the very secrets the log must not carry.
     */
    private function audited(AuditAction $action, User $user, string $type, string $label): void
    {
        $this->audit->record($action, $user, ['type' => $type, 'label' => $label]);
    }

    public function preferences(int $userId): NotificationPreferences
    {
        return $this->preferences->findForUser($userId);
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function savePreferences(int $userId, array $input): void
    {
        $errors = [];

        $stored = $this->preferences->findForUser($userId);

        // The Notifications page posts its chips as a list, behind a hidden
        // empty entry so that unticking every chip still names the setting.
        // A comma-separated string is still read, for any caller written
        // before the chips. A caller that sends neither has not asked, and
        // keeps what is stored — as with the two switches below.
        $leadInput = $input['lead_days'] ?? null;
        $leadDays = match (true) {
            is_array($leadInput) => $this->parseLeadDays(implode(',', array_filter(
                array_map(static fn (mixed $day): string => is_scalar($day) ? trim((string) $day) : 'x', $leadInput),
                static fn (string $day): bool => $day !== '',
            )), $errors),
            is_scalar($leadInput) => $this->parseLeadDays((string) $leadInput, $errors),
            default => $stored->leadDays,
        };

        $mode = DigestMode::tryFromString($this->str($input, 'digest_mode')) ?? DigestMode::Immediate;

        $dayRaw = trim($this->str($input, 'digest_day'));
        $day = $dayRaw === '' ? 1 : (int) $dayRaw;

        if ($mode === DigestMode::Weekly && ($day < 1 || $day > 7)) {
            $errors['digest_day'] = 'error.digest.weekday';
        }

        // Capped at 28 rather than 31: a monthly digest set for the 30th would
        // never be sent in February, and a notification feature that silently
        // skips a month is worse than one that is a few days early.
        if ($mode === DigestMode::Monthly && ($day < 1 || $day > 28)) {
            $errors['digest_day'] = 'error.digest.monthday';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->preferences->save(new NotificationPreferences(
            $userId,
            $leadDays,
            $mode,
            $day,
            // The form carries a hidden "0" before each switch, so a post from
            // it always names the setting. A caller that does not name it has
            // not asked, and keeps what is stored.
            array_key_exists('price_change_alerts', $input)
                ? $this->str($input, 'price_change_alerts') === '1'
                : $stored->priceChangeAlerts,
            array_key_exists('budget_alerts', $input)
                ? $this->str($input, 'budget_alerts') === '1'
                : $stored->budgetAlerts,
            array_key_exists('contract_alerts', $input)
                ? $this->str($input, 'contract_alerts') === '1'
                : $stored->contractAlerts,
            array_key_exists('backup_failed_alerts', $input)
                ? $this->str($input, 'backup_failed_alerts') === '1'
                : $stored->backupFailedAlerts,
            array_key_exists('backup_stale_alerts', $input)
                ? $this->str($input, 'backup_stale_alerts') === '1'
                : $stored->backupStaleAlerts,
            array_key_exists('backup_rehearsal_alerts', $input)
                ? $this->str($input, 'backup_rehearsal_alerts') === '1'
                : $stored->backupRehearsalAlerts,
        ));
    }

    /**
     * @return array<int, list<string>> Channel id => alert type values.
     */
    public function routes(int $userId): array
    {
        return $this->routes->findForUser($userId);
    }

    /**
     * Save routing from the settings form.
     *
     * The form submits a checkbox per channel per alert type. A channel with
     * every box ticked is stored as a row per type rather than as "all", so
     * that adding an alert type in a later phase does not silently subscribe
     * everybody to it. (The one type that arrived on by default — price
     * changes — did so through a migration copying each renewal route.)
     *
     * A grid with nothing ticked mutes every channel; it is not "unconfigured".
     *
     * @param array<int|string, mixed> $input routes[channelId][] = alertType
     */
    public function saveRoutes(int $userId, array $input): void
    {
        $known = [];
        $routes = [];
        foreach ($this->channels->findAllForUser($userId) as $channel) {
            $known[$channel->id] = true;
            // Every channel owns a row: one with nothing ticked is stored as
            // muted rather than as nothing, which would read as "not set up".
            $routes[$channel->id] = [];
        }

        foreach ($input as $channelId => $types) {
            $channelId = (int) $channelId;
            if (!isset($known[$channelId]) || !is_array($types)) {
                continue;
            }

            foreach ($types as $type) {
                $alert = AlertType::tryFromString(is_scalar($type) ? (string) $type : null);
                if ($alert !== null) {
                    $routes[$channelId][] = $alert;
                }
            }
        }

        $this->routes->replaceForUser($userId, $routes);
    }

    /**
     * The active channels one alert type should be delivered on.
     *
     * @return list<NotificationChannel>
     */
    public function channelsFor(int $userId, AlertType $type): array
    {
        $channels = $this->channels->findAllForUser($userId, true);
        if ($channels === []) {
            return [];
        }

        $routes = $this->routes->findForUser($userId);
        if ($routes === []) {
            // Nothing configured: everything goes everywhere. See the class
            // docblock — this is the default, not a fallback.
            return $channels;
        }

        $selected = [];
        foreach ($channels as $channel) {
            $allowed = $routes[$channel->id] ?? null;

            // A channel with no rows of its own in a user's otherwise-populated
            // routing is one they have turned everything off for. Treating it
            // as "all" here would resurrect a channel they deliberately muted.
            if ($allowed !== null && in_array($type->value, $allowed, true)) {
                $selected[] = $channel;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, ValidationError|string> $errors
     * @return list<int>
     */
    private function parseLeadDays(string $value, array &$errors): array
    {
        $value = trim($value);
        if ($value === '') {
            // An empty list is a deliberate "no reminders before a charge". The
            // other alert types are unaffected.
            return [];
        }

        $days = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (!ctype_digit($part) || (int) $part > self::MAX_LEAD_DAYS) {
                $errors['lead_days'] = 'error.lead_days.invalid';

                return [];
            }

            $days[] = (int) $part;
        }

        if (count($days) > self::MAX_LEAD_TIMES) {
            $errors['lead_days'] = new ValidationError('error.lead_days.too_many', ['max' => self::MAX_LEAD_TIMES]);

            return [];
        }

        return $days;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function label(array $input, string $fallback): string
    {
        $label = trim($this->str($input, 'label'));

        return $label === '' ? $fallback : mb_substr($label, 0, 100);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function isActive(array $input): bool
    {
        return ($input['is_active'] ?? '1') !== '0';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
