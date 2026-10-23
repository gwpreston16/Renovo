<?php

declare(strict_types=1);

namespace App\Application\Twig;

use App\Application\AppVersion;
use App\Domain\AlertType;
use App\Domain\BillingCycle;
use App\Domain\IsolationMode;
use App\Domain\BudgetPeriod;
use App\Domain\NoticePeriod;
use App\Domain\Entity\Subscription;
use App\Domain\Permission;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Domain\SubscriptionType;
use App\Domain\Entity\NotificationChannel;
use App\I18n\LocaleContext;
use App\I18n\Translator;
use App\Domain\Navigation;
use App\Notification\NotifierRegistry;
use App\Security\CsrfTokenManager;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Service\AuthService;
use App\Service\NavigationService;
use App\Service\ShellService;
use App\Service\ValidationError;
use App\Support\AssetVersion;
use App\Support\AvatarTone;
use App\Support\BuildManifest;
use App\Support\DateFormatter;
use App\Support\IconSprite;
use App\Support\MoneyFormatter;
use App\Support\NumberFormat;
use App\Support\RelativeTime;
use DateTimeImmutable;
use DateTimeInterface;
use Twig\Extension\AbstractExtension;
use App\Support\Initials;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The small set of helpers templates are allowed to use.
 *
 * Formatting and permission *questions* live here; no decision does. `can()`
 * exists so a template can hide a control the user cannot use, and is never
 * the thing that stops them using it — that is RequirePermissionMiddleware.
 *
 * Every label helper resolves an enum to a translation key and hands it to the
 * translator. The keys were already there — `BillingCycle::labelKey()` and its
 * siblings have returned `cycle.monthly` since Phase 1 — waiting for something
 * to look them up in.
 */
final class AppExtension extends AbstractExtension
{
    /** The catalogue prefix whose messages are handed to the browser. */
    private const JS_PREFIX = 'js.';

    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly PermissionService $permissions,
        private readonly CsrfTokenManager $csrf,
        private readonly NotifierRegistry $notifiers,
        private readonly Translator $translator,
        private readonly LocaleContext $locale,
        private readonly AssetVersion $assets,
        private readonly BuildManifest $build,
        private readonly NavigationService $navigation,
        private readonly ShellService $shell,
        private readonly DateFormatter $dates,
        private readonly NumberFormat $numbers,
        private readonly IconSprite $icons,
        private readonly RelativeTime $relativeTime,
        private readonly AppVersion $version,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translate(...)),
            new TwigFunction('asset', $this->assets->url(...)),
            new TwigFunction('bundle', $this->build->url(...)),
            new TwigFunction('icon', $this->icons->render(...), ['is_safe' => ['html']]),
            new TwigFunction('icon_sprite', $this->icons->url(...)),
            new TwigFunction('locale_tag', fn (): string => $this->locale->tag()),
            // The release, with its build if it has one. Drawn only by the
            // signed-in shell: an anonymous visitor is never told which
            // release an instance runs.
            new TwigFunction('app_version', fn (): string => $this->version->label()),
            new TwigFunction('js_translations', $this->jsTranslations(...)),
            new TwigFunction('error_message', $this->errorMessage(...)),
            new TwigFunction('csrf_token', fn (): string => $this->csrf->token()),
            new TwigFunction('csrf_field', $this->csrfField(...), ['is_safe' => ['html']]),
            new TwigFunction('can', $this->can(...)),
            new TwigFunction('can_edit_row', $this->canEditRow(...)),
            new TwigFunction('navigation', $this->navigationFor(...)),
            new TwigFunction('shell', $this->shell->forScope(...)),
            new TwigFunction('cycle_label', $this->cycleLabel(...)),
            new TwigFunction('type_label', $this->typeLabel(...)),
            new TwigFunction('role_label', $this->roleLabel(...)),
            new TwigFunction('isolation_label', $this->isolationLabel(...)),
            new TwigFunction('notice_label', $this->noticeLabel(...)),
            new TwigFunction('price_change_label', $this->priceChangeLabel(...)),
            new TwigFunction('split_label', $this->splitLabel(...)),
            new TwigFunction('budget_period_label', $this->budgetPeriodLabel(...)),
            new TwigFunction('alert_type_label', $this->alertTypeLabel(...)),
            new TwigFunction('channel_description', $this->channelDescription(...)),
            new TwigFunction('channel_fields', $this->channelFields(...)),
            new TwigFunction('channel_icon', $this->channelIcon(...)),
            new TwigFunction('channel_type_label', $this->channelTypeLabel(...)),
            new TwigFunction('avatar_tone', AvatarTone::of(...)),
            new TwigFunction('percent_symbol', $this->numbers->percentSymbol(...)),
            new TwigFunction('currency_symbol', $this->money->symbol(...)),
            // The password meter's thresholds, from the validator that applies
            // the rule — see AuthService::passwordMeterRules().
            new TwigFunction('password_rules', AuthService::passwordMeterRules(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->formatMoney(...)),
            new TwigFilter('local_date', $this->formatDate(...)),
            new TwigFilter('relative_date', $this->relativeTime->describe(...)),
            new TwigFilter('percent', $this->numbers->percent(...)),
            new TwigFilter('decimal', $this->numbers->decimal(...)),
            // For the places that have a name and no entity to ask — the owner
            // of a subscription is a display name on the row, not a User.
            new TwigFilter('initials', Initials::of(...)),
            new TwigFilter('first_initial', Initials::first(...)),
        ];
    }

    /**
     * A date in the reader's own locale.
     *
     * Twig's `date` filter formats with PHP's own names — "September", "Mon" —
     * whatever language the page is in, which is the one part of a translated
     * page that stays stubbornly English. This hands the job to ICU through
     * DateFormatter, which knows that a French reader wants "septembre" and
     * that a Japanese one wants the year first.
     *
     * The patterns are ICU skeletons, not strftime: `d MMM y` is "4 Sep 2026"
     * in English and "4 sept. 2026" in French, with the order decided by the
     * locale rather than by this file.
     *
     * The formatting itself lives in the service rather than here because
     * Phase 10's chart builds its axis labels before any template runs, and a
     * label on the axis and a date in the table beneath it must be the same
     * string for the same day.
     */
    public function formatDate(
        DateTimeInterface|string|null $value,
        string $pattern = 'd MMM y',
    ): string {
        if ($value === null || $value === '') {
            return $this->translator->trans('common.none_symbol');
        }

        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable($value);

        return $this->dates->format($date, $pattern);
    }

    public function formatMoney(?int $amountMinor, string $currency, bool $signed = false): string
    {
        return $this->money->formatMinor($amountMinor ?? 0, $currency, $signed);
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    public function translate(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }

    /**
     * The strings the page's JavaScript needs, as a JSON object.
     *
     * Rendered into the layout from the same catalogue the server reads, so
     * the completeness check covers them — a separate JavaScript catalogue
     * would be one nothing checks, and it would drift the first time somebody
     * added a string to only one of the two.
     */
    public function jsTranslations(): string
    {
        $messages = [];
        foreach ($this->translator->group(self::JS_PREFIX) as $key => $message) {
            $messages[substr($key, strlen(self::JS_PREFIX))] = $message;
        }

        // The tag-escaping flags matter: this JSON is written inside a
        // <script> element, and a message containing "</script>" would
        // otherwise end the element early.
        return json_encode(
            $messages,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        );
    }

    /**
     * One field's validation failure, as a sentence.
     *
     * The service raised a key; this is where it becomes words. Doing it here
     * rather than inside the service is what keeps a Translator out of twenty
     * constructors, and it is the same resolution the API's error envelope
     * performs on the same object.
     */
    public function errorMessage(ValidationError|string|null $error): string
    {
        if ($error === null) {
            return '';
        }

        if (is_string($error)) {
            return $this->translator->trans($error);
        }

        return $this->translator->trans($error->key, $error->parameters);
    }

    public function alertTypeLabel(?string $value): string
    {
        $type = AlertType::tryFromString($value);

        return $type === null ? (string) $value : $this->translator->trans($type->labelKey());
    }

    /**
     * A channel's destination, as its own notifier chooses to describe it.
     *
     * Asked of the notifier rather than formatted here, because only the
     * notifier knows which of its configuration fields are safe to show. A
     * template that reached into the config itself would eventually print a
     * token.
     */
    public function channelDescription(NotificationChannel $channel): string
    {
        return $this->notifiers->find($channel->type)?->describe($channel->config) ?? '';
    }

    /**
     * The configuration fields a stored channel's type declares, so the edit
     * form can be rendered without the template knowing what a Gotify or a
     * Slack channel needs.
     *
     * @return list<\App\Notification\ChannelField>
     */
    public function channelFields(NotificationChannel $channel): array
    {
        return $this->notifiers->find($channel->type)?->fields() ?? [];
    }

    /**
     * The icon a channel type is drawn with: a letter for email, a hook for a
     * webhook, a speech bubble for the chat services and a bell for the push
     * services. Only icons already in the sprite.
     */
    public function channelIcon(string $type): string
    {
        return match ($type) {
            'email' => 'mail',
            'webhook' => 'webhook',
            'slack', 'discord', 'mattermost', 'telegram' => 'message',
            default => 'bell',
        };
    }

    /**
     * A channel type's own name — "Slack", "Webhook" — as against the label
     * its owner gave the channel.
     */
    public function channelTypeLabel(string $type): string
    {
        return $this->notifiers->find($type)?->label() ?? $type;
    }

    public function csrfField(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            CsrfTokenManager::FIELD_NAME,
            htmlspecialchars($this->csrf->token(), ENT_QUOTES, 'UTF-8'),
        );
    }

    /**
     * The shell's navigation for this request.
     *
     * The template is handed a finished list — which items, in which groups,
     * and which one is the page being rendered — because deciding any of that
     * in Twig would put the answer in two places the first time the narrow
     * layout wanted it too. See NavigationService.
     */
    public function navigationFor(?Scope $scope, string $path): Navigation
    {
        return $this->navigation->forPath($scope, $path);
    }

    public function can(?Scope $scope, string $permission): bool
    {
        $resolved = Permission::tryFrom($permission);

        return $scope !== null && $resolved !== null && $this->permissions->allows($scope, $resolved);
    }

    /**
     * Whether this user may change *this row*, as against rows of its kind.
     *
     * `can()` answers a question about a role — "may they edit a subscription"
     * — and for most of this application's life that was the whole answer,
     * because everybody who could see a row could change it. Two things break
     * that: a split participant sees a subscription in ISOLATED mode without
     * owning it, and a Contributor sees the whole household and owns only part
     * of it. Both would be drawn a Pause and a Delete button by a permission
     * check alone, and both would be answered 404 for using them.
     *
     * So a control attached to one row asks this instead, which is
     * `Scope::mayWriteRow()` — the same rule the repository compiles into the
     * UPDATE. This decides whether a button is *drawn*; the repository is still
     * what refuses a forged request, and neither stands in for the other.
     */
    public function canEditRow(?Scope $scope, ?Subscription $subscription): bool
    {
        if ($scope === null || $subscription === null) {
            return false;
        }

        return $scope->mayWriteRow($subscription->householdId, $subscription->ownerUserId);
    }

    /**
     * A billing cycle, including the custom one, whose message carries the
     * day count as an ICU plural so that a language with more than two forms
     * can state all of them. An interval above 1 — every six months, every
     * two weeks — is a plural message of its own; at 1 the cycle's plain
     * name is unchanged.
     */
    public function cycleLabel(?string $cycle, ?int $cycleDays = null, ?int $interval = null): string
    {
        $resolved = BillingCycle::tryFromString($cycle);

        if ($resolved === null) {
            return $this->translator->trans('common.none_symbol');
        }

        if ($resolved === BillingCycle::CustomDays) {
            return $cycleDays === null
                ? $this->translator->trans('cycle.custom')
                : $this->translator->trans($resolved->labelKey(), ['days' => $cycleDays]);
        }

        if ($interval !== null && $interval > 1 && $resolved->allowsInterval()) {
            return $this->translator->trans($resolved->labelKey() . '_every', ['n' => $interval]);
        }

        return $this->translator->trans($resolved->labelKey());
    }

    public function typeLabel(?string $type): string
    {
        return $this->label(SubscriptionType::tryFrom($type ?? '')?->labelKey());
    }

    public function roleLabel(?string $role): string
    {
        return $this->label(Role::tryFrom($role ?? '')?->labelKey());
    }

    public function isolationLabel(?string $mode): string
    {
        return $this->label(IsolationMode::tryFrom($mode ?? '')?->labelKey());
    }

    /**
     * Why a price changed. Worth naming: a trial ending and a provider putting
     * their prices up look identical on a chart unless the entry says which.
     */
    public function priceChangeLabel(?string $source): string
    {
        $resolved = PriceChangeSource::tryFrom($source ?? '');

        return $this->translator->trans($resolved?->labelKey() ?? 'price_change.unknown');
    }

    public function splitLabel(?string $mode): string
    {
        return $this->label(SplitMode::tryFrom($mode ?? '')?->labelKey());
    }

    public function budgetPeriodLabel(?string $period): string
    {
        return $this->label(BudgetPeriod::tryFrom($period ?? '')?->labelKey());
    }

    public function noticeLabel(?int $amount, ?string $unit): string
    {
        $period = NoticePeriod::of($amount, $unit);

        if (!$period->isSet()) {
            return $this->translator->trans('notice.none');
        }

        return $this->translator->trans($period->labelKey(), ['count' => (int) $period->amount]);
    }

    /**
     * An enum label, or the em dash that stands for "nothing recorded" — the
     * one string in this class that is not a sentence and still has to be
     * translatable, because not every script writes an absence as "—".
     */
    private function label(?string $key): string
    {
        return $this->translator->trans($key ?? 'common.none_symbol');
    }
}
