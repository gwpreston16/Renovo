<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\NotificationPreferences;
use App\Domain\Entity\User;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Notification\NotifierException;
use App\Repository\HouseholdRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationSettingsService;
use App\Support\Clock;

/**
 * The first-run wizard's second and third steps: the household, and reminders.
 *
 * Step one — the owner account — is SetupService, and it is the only step that
 * runs before anybody is signed in. Everything here runs afterwards, as the new
 * instance administrator, and every setting it touches is one Settings or
 * Notifications already changes: the household's name, the base currency, the
 * isolation mode, the rate provider, lead times, budget alerts, channels. So
 * nothing here decides anything new — each value goes through the service that
 * owns it, and a step left on its defaults changes nothing at all.
 *
 * The one thing the steps hold between requests is the invitation list. It is
 * checked on the household step, where a bad address can be corrected, and
 * sent when setup finishes — so an owner who goes back and changes their mind
 * has invited nobody yet.
 */
final class SetupWizardService
{
    /**
     * The currencies offered first, above the full ISO list. A handful, not
     * Currency::common()'s twenty-seven: the point is that most people find
     * theirs without scrolling.
     */
    public const FEATURED_CURRENCIES = ['GBP', 'EUR', 'USD', 'CAD', 'AUD'];

    /** The most addresses the step takes at once; more is a Members & roles job. */
    public const MAX_INVITES = 20;

    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly UserRepository $users,
        private readonly HouseholdSettingsService $householdSettings,
        private readonly InstanceAdminService $instanceAdmin,
        private readonly InstanceSettingsService $settings,
        private readonly ExchangeRateService $rates,
        private readonly ExchangeRateProviderRegistry $providers,
        private readonly HouseholdMemberService $members,
        private readonly NotificationSettingsService $notifications,
        private readonly NotificationDispatcher $dispatcher,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Whether the signed-in steps are still open to this user: an instance
     * administrator, before the wizard has been finished.
     */
    public function isOpenTo(User $user): bool
    {
        return $user->isInstanceAdmin && !$this->settings->isNotificationSetupComplete();
    }

    /**
     * What the household step shows: the values as they stand, so going back
     * to it shows what was chosen rather than the defaults again.
     *
     * @return array<string, mixed>
     */
    public function householdStep(int $householdId): array
    {
        $household = $this->households->findById($householdId);

        $featured = array_values(array_filter(
            self::FEATURED_CURRENCIES,
            static fn (string $code): bool => Currency::isValidCode($code),
        ));

        return [
            'household_name' => $household->name ?? '',
            'base_currency' => $this->settings->baseCurrency(),
            'featured_currencies' => $featured,
            'currencies' => array_values(array_diff(Currency::all(), $featured)),
            'isolation_mode' => $this->settings->isolationMode()->value,
            'isolation_modes' => IsolationMode::cases(),
            'rate_provider' => $this->rates->provider()->key(),
            'rate_providers' => $this->providers->all(),
        ];
    }

    /**
     * Save the household step, and return the invitations it asked for.
     *
     * The addresses are checked before anything is written, so a typo in the
     * third one leaves the step exactly as it was submitted rather than half
     * saved behind an error.
     *
     * @param array<string, mixed> $input
     * @return list<string> Addresses to invite when setup finishes.
     * @throws ValidationException
     */
    public function saveHousehold(User $admin, int $householdId, array $input): array
    {
        $errors = [];

        $name = trim($this->str($input, 'household_name'));
        if ($name === '') {
            $errors['household_name'] = 'error.setup.household_name_required';
        } elseif (mb_strlen($name) > 100) {
            $errors['household_name'] = 'error.name.too_long_100';
        }

        $currency = Currency::normalise($this->str($input, 'base_currency'));
        if (!Currency::isValidCode($currency)) {
            $errors['base_currency'] = 'error.currency.required';
        }

        $invites = $this->parseInvites($admin, $this->str($input, 'invites'), $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->householdSettings->rename($admin, $householdId, $name);

        $this->instanceAdmin->apply($admin, [
            'base_currency' => $currency,
            'isolation_mode' => $this->str($input, 'isolation_mode'),
            'rate_provider' => $this->str($input, 'rate_provider'),
            'rate_provider_key' => $this->str($input, 'rate_provider_key'),
        ]);

        return $invites;
    }

    /**
     * What the reminders step shows.
     *
     * @return array<string, mixed>
     */
    public function remindersStep(User $admin): array
    {
        $preferences = $this->notifications->preferences($admin->id);
        $channels = $this->notifications->channels($admin->id);
        $email = $this->emailChannel($channels);

        return [
            // On until turned off, as the prototype has it: the owner's own
            // address is the one channel that needs no configuring.
            'email_on' => $email === null || $email->isActive,
            'budget_alerts' => $preferences->budgetAlerts,
            'lead_days' => $preferences->leadDays,
            'offered_lead_days' => NotificationPreferences::OFFERED_LEAD_DAYS,
            'channels' => array_values(array_filter(
                $channels,
                static fn (NotificationChannel $channel): bool => $channel !== $email,
            )),
            'email_channel' => $email,
        ];
    }

    /**
     * Save the reminders step: the email switch, budget alerts, lead times.
     *
     * "Email" is a channel, as it is on the Notifications page: on means the
     * owner has an active email channel to their own address, off means any
     * they have is switched off — not deleted, so turning it back on later
     * brings back its routing.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function saveReminders(User $admin, array $input): void
    {
        $preferences = $this->notifications->preferences($admin->id);

        $this->notifications->savePreferences($admin->id, [
            'lead_days' => is_array($input['lead_days'] ?? null) ? $input['lead_days'] : [],
            'budget_alerts' => $this->str($input, 'budget_alerts') === '1' ? '1' : '0',
            // Not on this step, so kept as they are.
            'digest_mode' => $preferences->digestMode->value,
            'digest_day' => (string) $preferences->digestDay,
            'price_change_alerts' => $preferences->priceChangeAlerts ? '1' : '0',
            'contract_alerts' => $preferences->contractAlerts ? '1' : '0',
        ]);

        $emailOn = $this->str($input, 'email') === '1';
        $email = $this->emailChannel($this->notifications->channels($admin->id));

        if ($email === null && $emailOn) {
            // No address: the channel sends to the account's own, and follows
            // it if the owner changes it later.
            $this->notifications->createChannel($admin->id, ['channel_type' => 'email', 'address' => '']);
        } elseif ($email !== null && $email->isActive !== $emailOn) {
            $this->notifications->setChannelActive($admin->id, $email->id, $emailOn);
        }
    }

    /**
     * Send a test message through the owner's email channel — the proof that
     * the relay the instance was started with actually delivers.
     *
     * @return bool False when Email is off, so there is nothing to test.
     * @throws NotifierException when the relay refuses or cannot be reached.
     */
    public function sendTestEmail(User $admin): bool
    {
        $email = $this->emailChannel($this->notifications->channels($admin->id));

        if ($email === null || !$email->isActive) {
            return false;
        }

        $this->dispatcher->sendTest($admin, $email);

        return true;
    }

    /**
     * Finish: send the invitations the household step collected, and close
     * the wizard.
     *
     * An invitation that fails now — somebody registered that address in the
     * meantime — is reported on the done page, not allowed to stop setup
     * finishing: the rest of setup has already been saved, and the invite can
     * be sent again from Members & roles.
     *
     * @param list<string> $invites
     * @return array{household: string, currency: string, invited: list<string>, failed: list<string>}
     */
    public function finish(User $admin, Scope $scope, array $invites): array
    {
        $invited = [];
        $failed = [];

        foreach ($invites as $address) {
            try {
                $this->members->add($admin, $scope, $this->nameFrom($address), $address, Role::Contributor, false);
                $invited[] = $address;
            } catch (ValidationException) {
                $failed[] = $address;
            }
        }

        $this->settings->markNotificationSetupComplete($this->clock->now()->format('Y-m-d H:i:s'));

        $household = $scope->householdId === null ? null : $this->households->findById($scope->householdId);

        return [
            'household' => $household->name ?? '',
            'currency' => $this->settings->baseCurrency(),
            'invited' => $invited,
            'failed' => $failed,
        ];
    }

    /**
     * @param array<string, ValidationError|string> $errors
     * @return list<string>
     */
    private function parseInvites(User $admin, string $raw, array &$errors): array
    {
        $addresses = [];
        $bad = [];

        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $address = $this->users->normaliseEmail($candidate);

            if (
                !filter_var($address, FILTER_VALIDATE_EMAIL)
                || $address === $admin->email
                || $this->users->emailExists($address)
            ) {
                $bad[] = $candidate;

                continue;
            }

            $addresses[$address] = true;
        }

        if ($bad !== []) {
            $errors['invites'] = new ValidationError('error.setup.invites_invalid', [
                'addresses' => implode(', ', $bad),
            ]);
        } elseif (count($addresses) > self::MAX_INVITES) {
            $errors['invites'] = new ValidationError('error.setup.invites_too_many', ['max' => self::MAX_INVITES]);
        }

        return array_keys($addresses);
    }

    /**
     * A display name for somebody known only by their address: the part
     * before the @, which they replace with their own once they are in.
     */
    private function nameFrom(string $address): string
    {
        $local = strstr($address, '@', true);

        return mb_substr($local === false || $local === '' ? $address : $local, 0, 100);
    }

    /**
     * @param list<NotificationChannel> $channels
     */
    private function emailChannel(array $channels): ?NotificationChannel
    {
        foreach ($channels as $channel) {
            if ($channel->type === 'email') {
                return $channel;
            }
        }

        return null;
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
