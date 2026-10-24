<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Domain\TokenScopes;
use App\Service\ApiTokenService;
use App\Service\ValidationException;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use App\Security\SessionInterface;
use App\Support\Clock;

/**
 * Issuing and revoking the signed-in user's API tokens.
 *
 * No permission is named on these routes, for the same reason the notification
 * channels and the passkey routes name none: every one of them acts on the
 * authenticated user's own id, and a token can never do more than its owner
 * can. A Viewer may issue one and will find it reads exactly what they can read
 * and writes nothing.
 *
 * The full token is put in a one-shot flash rather than held in the page model,
 * so a refresh of the settings page does not redisplay it and it never reaches
 * a template through a route that could be linked to. The page that reads it
 * is `SettingsController::data()`.
 */
final class ApiTokenController extends Controller
{
    /** Read, once, by the Data & integrations tab the issue lands on. */
    public const FLASH_NEW_TOKEN = 'new_api_token';

    private const SECTION = SettingsController::DATA . '#api-tokens';

    /**
     * Where an issue or a reissue lands: the callout at the top of the tab
     * that holds the one copy of the secret, not the tokens section further
     * down, which on a phone would scroll the reader straight past it.
     */
    private const NEW_TOKEN = SettingsController::DATA . '#new-token';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly ApiTokenService $tokens,
        private readonly Clock $clock,
    ) {
        parent::__construct($view, $session, $translator);
    }

    /**
     * The page these lived on became a section of Settings' Data &
     * integrations tab in Phase 28. The path stays as a redirect for anybody
     * who bookmarked it.
     */
    public function moved(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirect($response, self::SECTION);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $scope = $this->scope($request);

        try {
            $token = $this->tokens->issue(
                $this->user($request),
                $scope->householdId,
                is_scalar($body['name'] ?? null) ? (string) $body['name'] : '',
                $this->scopes($body),
                $this->expiry($body),
            );
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);

            return $this->redirectAfterWrite($request, $response, self::SECTION);
        }

        $this->session->set(self::FLASH_NEW_TOKEN, $token);
        $this->flash('success', 'flash.token_created');

        return $this->redirectAfterWrite($request, $response, self::NEW_TOKEN);
    }

    /**
     * Rotate a token: the old secret dies, a new one with the same name,
     * scopes and lifetime is shown once.
     *
     * The new secret goes into the same one-shot flash a fresh issue uses, so
     * it is displayed exactly once and a refresh of this page does not bring it
     * back. Revoke is left alone beside it — reissuing and revoking are
     * different intentions, and a button that did both would serve neither.
     */
    public function reissue(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        try {
            $token = $this->tokens->reissue($this->user($request), (int) $id);
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);

            return $this->redirectAfterWrite($request, $response, self::SECTION);
        }

        $this->session->set(self::FLASH_NEW_TOKEN, $token);
        $this->flash('success', 'flash.token_reissued');

        return $this->redirectAfterWrite($request, $response, self::NEW_TOKEN);
    }

    public function revoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $revoked = $this->tokens->revoke($this->user($request), (int) $id);

        $this->flash(
            $revoked ? 'success' : 'error',
            $revoked ? 'flash.token_revoked' : 'flash.token_revoke_failed',
        );

        return $this->redirectAfterWrite($request, $response, self::SECTION);
    }

    /**
     * The scopes the form asked for: a preset's, or for Custom the switches
     * that were on. A form with no preset at all gets the default, Read
     * everything; an empty Custom is refused by the service.
     *
     * @param array<string, mixed> $body
     */
    private function scopes(array $body): TokenScopes
    {
        $preset = is_scalar($body['preset'] ?? null) ? (string) $body['preset'] : TokenScopes::PRESET_READ;
        if ($preset !== TokenScopes::PRESET_CUSTOM) {
            return TokenScopes::preset($preset) ?? TokenScopes::readEverything();
        }

        $chosen = is_array($body['scopes'] ?? null) ? $body['scopes'] : [];

        return TokenScopes::fromValues(array_values(array_filter($chosen, 'is_string')));
    }

    /**
     * When the token expires: a number of days from today, a date, or never.
     * A form that says nothing gets the default lifetime.
     *
     * @param array<string, mixed> $body
     * @throws ValidationException
     */
    private function expiry(array $body): ?DateTimeImmutable
    {
        $lifetime = is_scalar($body['lifetime'] ?? null)
            ? (string) $body['lifetime']
            : (string) $this->tokens->defaultLifetimeDays();

        if ($lifetime === 'none') {
            return null;
        }

        if (ctype_digit($lifetime) && (int) $lifetime > 0) {
            return $this->clock->now()->modify(sprintf('+%d days', (int) $lifetime))->setTime(23, 59, 59);
        }

        // On a date: the date field, which has to hold one. A blank or
        // unreadable date is refused rather than read as No expiry — that is
        // a chip of its own, and a forgotten field must not choose it.
        $raw = is_scalar($body['expires_at'] ?? null) ? trim((string) $body['expires_at']) : '';
        $date = $raw === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw ValidationException::field('expires_at', 'error.token.expiry_date_required');
        }

        // End of the chosen day: a token that said it expired on the 31st and
        // stopped working at midnight on the 30th would be a support ticket.
        return $date->setTime(23, 59, 59);
    }
}
