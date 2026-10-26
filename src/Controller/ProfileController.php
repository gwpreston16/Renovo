<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Density;
use App\Domain\LandingView;
use App\Domain\Palette;
use App\Domain\Theme;
use App\Domain\WeekStart;
use App\I18n\Locales;
use App\I18n\Translator;
use App\Security\SafeRedirectTarget;
use App\Security\SessionInterface;
use App\Service\Auth\RecoveryCodeService;
use App\Service\Auth\SessionDirectoryService;
use App\Service\Auth\TotpService;
use App\Service\Auth\TwoFactorService;
use App\Service\Auth\WebAuthnService;
use App\Service\AvatarStorage;
use App\Service\UserPreferencesService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The account's own page: who one person is, how they get in, and how Renovo
 * looks and behaves for them.
 *
 * Separate from Settings because the two answer different questions. Nothing
 * here needs a permission — every value changes what one account sees and
 * nothing that anybody else does — whereas most of Settings is a household or
 * an instance deciding something on everybody's behalf.
 *
 * `/profile`, `/profile/account` and `/settings/security` used to be three
 * screens, and the lines between them were not ones a reader had to draw:
 * each acted on the id in the session and none needed a permission. They are
 * one page now. This controller renders it; `AccountController` answers the
 * name, address, password and picture forms on it and `SecurityController`
 * the second factors and sessions — one page, three controllers, because
 * "how this looks", "who is looking" and "how they get in" remain different
 * subjects however they are laid out.
 */
final class ProfileController extends Controller
{
    private const APPEARANCE = '/profile#appearance';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly UserPreferencesService $preferences,
        private readonly Locales $locales,
        // For the picture card's size hint. The uploads themselves are
        // AccountController's, and so is every other write this page's forms
        // make; what this controller owns is the page they are drawn on.
        private readonly AvatarStorage $avatarStorage,
        // Read-only here, for the two-step and sessions sections. Their
        // writes are SecurityController's.
        private readonly TotpService $totp,
        private readonly TwoFactorService $twoFactor,
        private readonly WebAuthnService $webAuthn,
        private readonly SessionDirectoryService $sessions,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);

        // A member still on the temporary password an Owner gave them is held
        // on this page, and on this page only the password form works — so
        // they see that form and nothing else, in the signed-out layout: the
        // shell's navigation would be a list of links that all bring them
        // straight back here. See PasswordChangeRequiredMiddleware.
        if ($user->mustChangePassword) {
            return $this->render($request, $response, 'auth/change_password.twig');
        }

        // Shown once: read and forgotten in the same breath, so a refresh
        // cannot bring them back. See SecurityController::RECOVERY_CODES_KEY.
        $codes = $this->session->get(SecurityController::RECOVERY_CODES_KEY);
        $this->session->remove(SecurityController::RECOVERY_CODES_KEY);

        $locales = $this->locales->choices();

        return $this->render($request, $response, 'profile/index.twig', [
            'themes' => Theme::cases(),
            'palettes' => Palette::cases(),
            'densities' => Density::cases(),
            'week_starts' => WeekStart::cases(),
            'landing_views' => LandingView::cases(),
            // A choice between one catalogue and the instance default — which
            // is that same catalogue — is not a choice, so the control is not
            // drawn until a second language exists.
            'locale_choices' => count($locales) > 1 ? $locales : [],
            'avatar_max_kilobytes' => max(1, intdiv($this->avatarStorage->maxBytes(), 1024)),
            'totp' => [
                'enabled_since' => $this->totp->enabledSince($user->id),
            ],
            'recovery' => [
                'remaining' => $this->twoFactor->unusedRecoveryCodeCount($user->id),
                'total' => RecoveryCodeService::CODE_COUNT,
                'applicable' => $this->twoFactor->isRequiredFor($user->id),
            ],
            'recovery_codes' => is_array($codes) ? $codes : [],
            'passkeys' => $this->webAuthn->credentialsFor($user->id),
            'sessions' => $this->sessions->listFor($user, $this->session->id()),
        ]);
    }

    /**
     * The theme switch, which appears on every page and therefore submits on
     * its own rather than as part of the profile form.
     */
    public function updateTheme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $theme = is_scalar($body['theme'] ?? null) ? (string) $body['theme'] : null;

        $saved = $this->preferences->updateTheme($this->user($request)->id, $theme);

        // The top bar's toggle, through htmx: nothing to navigate to, only
        // the root's `data-theme` to change, which app.js does on this event.
        if ($this->isHtmx($request)) {
            return $response
                ->withHeader('HX-Trigger', (string) json_encode(['renovo:theme' => ['theme' => $saved->value]]))
                ->withStatus(204);
        }

        // Back where they were: the toggle is in the top bar, so sending them
        // to the profile page from an arbitrary page would be a navigation
        // they did not ask for. The form names the page it was on; a browser
        // that strips the field falls back to the Referer, and either is
        // reduced to a path on this host before it is followed.
        $return = is_string($body['return'] ?? null) ? $this->localPath($body['return']) : null;
        $referer = $request->getHeaderLine('Referer');
        $target = $return ?? ($referer !== '' ? $this->samePathAsUs($request, $referer) : '/profile');

        return $this->redirectAfterWrite($request, $response, $target);
    }

    /**
     * Appearance & preferences.
     *
     * With script the form posts on every change, and the answer is a 204
     * carrying what was saved, which app.js puts on the root element so the
     * page restyles under the reader without a reload. Without script it is
     * one button and a redirect back to the section.
     *
     * A refusal is never a silent 204: it becomes a flash and a full load of
     * the section, with or without script, so the error is on screen.
     */
    public function updatePreferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $saved = $this->preferences->update($this->user($request)->id, $this->body($request));
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);

            // Not HX-Redirect: htmx follows it by assigning the address, and
            // `/profile#appearance` from `/profile` differs only by its
            // fragment, so the browser would scroll and never load the page
            // the error is on. A refresh reloads it, fragment and all.
            if ($this->isHtmx($request)) {
                return $response->withHeader('HX-Refresh', 'true')->withStatus(204);
            }

            return $this->redirect($response, self::APPEARANCE);
        }

        if ($this->isHtmx($request)) {
            $detail = array_filter($saved, static fn (?string $value): bool => $value !== null)
                + ['message' => $this->translator->trans('flash.preferences_saved')];

            return $response
                ->withHeader('HX-Trigger', (string) json_encode(['renovo:preferences' => $detail]))
                ->withStatus(204);
        }

        $this->flash('success', 'flash.preferences_saved');

        return $this->redirectAfterWrite($request, $response, self::APPEARANCE);
    }

    /**
     * A Referer, reduced to a path on this instance.
     *
     * Never used as a redirect target as it arrived: an absolute URL in that
     * header is attacker-controllable, and handing it to a Location header is
     * an open redirect. Only the path survives, and only when the host matches.
     */
    private function samePathAsUs(ServerRequestInterface $request, string $referer): string
    {
        $parts = parse_url($referer);
        if ($parts === false) {
            return '/profile';
        }

        $host = $parts['host'] ?? null;
        if ($host !== null && $host !== $request->getUri()->getHost()) {
            return '/profile';
        }

        return $this->localPath($parts['path'] ?? '/') ?? '/profile';
    }

    /**
     * A path on this host, or null.
     */
    private function localPath(string $path): ?string
    {
        return SafeRedirectTarget::path($path);
    }
}
