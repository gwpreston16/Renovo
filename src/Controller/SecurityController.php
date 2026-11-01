<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Security\Totp;
use App\Service\Auth\SessionDirectoryService;
use App\Service\Auth\TotpService;
use App\Service\Auth\TwoFactorService;
use App\Service\Auth\WebAuthnService;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Account security: authenticator app, passkeys, recovery codes, sessions.
 *
 * Writes only, since Phase 27. The controls are sections of `/profile`, which
 * `ProfileController` renders, and every action here lands back on the section
 * it came from. The one page this still draws is the authenticator's QR step,
 * which is a stage of enrolment rather than a place to visit.
 *
 * Every route here acts on the signed-in user's own id, taken from the session
 * and never from the request, so there is no permission to check beyond being
 * signed in — and no way to point any of it at somebody else's account. A
 * Viewer securing their own login is not a household write.
 *
 * The recovery codes are shown once, through a flash-like session key that is
 * consumed by the render. They are not stored anywhere readable, so a page
 * refresh that lost them would mean the user cannot get back in if their phone
 * goes missing — and keeping them retrievable would defeat hashing them.
 */
final class SecurityController extends Controller
{
    private const ENROLMENT_SECRET_KEY = 'totp_enrolment_secret';
    /**
     * Where freshly issued recovery codes wait for the one render that shows
     * them. Public because that render is the profile's, in ProfileController;
     * one name for the key, owned here where the codes are issued.
     */
    public const RECOVERY_CODES_KEY = 'recovery_codes_once';

    private const TWO_STEP = '/profile#two-step';
    private const SESSIONS = '/profile#sessions';
    private const REGISTRATION_OPTIONS_KEY = 'passkey_registration_options';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly TotpService $totp,
        private readonly TwoFactorService $twoFactor,
        private readonly WebAuthnService $webAuthn,
        private readonly SessionDirectoryService $sessions,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($view, $session, $translator);
    }

    /**
     * Where this screen used to be. Kept for bookmarks, and pointed at the
     * section that replaced it rather than the top of a long page.
     */
    public function moved(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirect($response, self::TWO_STEP);
    }

    /**
     * Step one of enrolment: show the QR code and wait for a code back.
     */
    public function startTotp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);

        if ($this->totp->isEnabled($user->id)) {
            $this->flash('error', 'error.totp.already_set_up');

            return $this->redirectAfterWrite($request, $response, self::TWO_STEP);
        }

        $enrolment = $this->totp->beginEnrolment($user, $this->settings->instanceName());
        $this->session->set(self::ENROLMENT_SECRET_KEY, $enrolment['secret']);

        return $this->render($request, $response, 'security/totp_setup.twig', [
            'secret' => $enrolment['formatted_secret'],
            'uri' => $enrolment['uri'],
            'qr_code' => $this->qrCode($enrolment['uri']),
            'errors' => [],
        ]);
    }

    public function confirmTotp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $body = $this->body($request);

        try {
            $codes = $this->totp->confirmEnrolment(
                $user,
                is_scalar($body['code'] ?? null) ? (string) $body['code'] : '',
            );
        } catch (ValidationException $exception) {
            $secret = $this->session->get(self::ENROLMENT_SECRET_KEY);
            $uri = is_string($secret) && $secret !== ''
                ? Totp::provisioningUri($secret, $user->email, $this->settings->instanceName())
                : '';

            return $this->render($request, $response->withStatus(422), 'security/totp_setup.twig', [
                'secret' => is_string($secret) ? Totp::formatSecret($secret) : '',
                'uri' => $uri,
                'qr_code' => $uri === '' ? '' : $this->qrCode($uri),
                'errors' => $exception->errors(),
            ]);
        }

        $this->session->remove(self::ENROLMENT_SECRET_KEY);
        $this->session->set(self::RECOVERY_CODES_KEY, $codes);

        $this->flash('success', 'flash.totp_enabled');

        return $this->redirectAfterWrite($request, $response, self::TWO_STEP);
    }

    public function cancelTotp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->totp->cancelEnrolment($this->user($request)->id);
        $this->session->remove(self::ENROLMENT_SECRET_KEY);

        return $this->redirectAfterWrite($request, $response, self::TWO_STEP);
    }

    public function disableTotp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->totp->disable(
                $this->user($request),
                is_scalar($body['password'] ?? null) ? (string) $body['password'] : '',
                $this->clientIp($request),
            );
            $this->flash('success', 'flash.totp_disabled');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->redirectAfterWrite($request, $response, self::TWO_STEP);
    }

    public function regenerateRecoveryCodes(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body = $this->body($request);

        try {
            $codes = $this->twoFactor->regenerateRecoveryCodes(
                $this->user($request),
                is_scalar($body['password'] ?? null) ? (string) $body['password'] : '',
                $this->clientIp($request),
            );

            $this->session->set(self::RECOVERY_CODES_KEY, $codes);
            $this->flash('success', 'flash.recovery_codes_issued');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->redirectAfterWrite($request, $response, self::TWO_STEP);
    }

    /**
     * Options for registering a new passkey.
     */
    public function passkeyOptions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = json_decode((string) $request->getBody(), true);
        $password = is_array($payload) && is_scalar($payload['password'] ?? null) ? (string) $payload['password'] : '';

        // The password is checked before a challenge exists, and the challenge
        // kept in the session is the only one a registration can complete
        // against (K174).
        try {
            $creation = $this->webAuthn->beginRegistration(
                $this->user($request),
                $password,
                $this->clientIp($request),
            );
        } catch (ValidationException $exception) {
            $this->session->remove(self::REGISTRATION_OPTIONS_KEY);

            return $this->json($response->withStatus(422), ['error' => $this->errorSentence($exception)]);
        }

        $options = $this->webAuthn->serializeOptions($creation);

        $this->session->set(self::REGISTRATION_OPTIONS_KEY, $options);

        return $this->jsonRaw($response, $options);
    }

    public function registerPasskey(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $stored = $this->session->get(self::REGISTRATION_OPTIONS_KEY);
        if (!is_string($stored) || $stored === '') {
            return $this->json($response->withStatus(400), ['error' => 'Start again — that challenge has expired.']);
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload) || !isset($payload['credential'])) {
            return $this->json(
                $response->withStatus(400),
                ['error' => $this->translator->trans('error.passkey.unexpected_response')],
            );
        }

        $name = is_scalar($payload['name'] ?? null) ? (string) $payload['name'] : '';
        $discoverable = ($payload['discoverable'] ?? null) === true;

        try {
            $credential = $this->webAuthn->completeRegistration(
                $this->user($request),
                json_encode($payload['credential'], JSON_THROW_ON_ERROR),
                $this->webAuthn->deserializeCreationOptions($stored),
                $name,
                $discoverable,
            );
        } catch (ValidationException $exception) {
            return $this->json($response->withStatus(422), ['error' => $this->errorSentence($exception)]);
        } finally {
            $this->session->remove(self::REGISTRATION_OPTIONS_KEY);
        }

        // A passkey is a second factor too: registering the first one has just
        // made this account's password login depend on a device, so it gets the
        // same way back in that enrolling an authenticator does.
        $codes = $this->twoFactor->ensureRecoveryCodes($this->user($request));
        if ($codes !== []) {
            $this->session->set(self::RECOVERY_CODES_KEY, $codes);
        }

        $this->flash('success', 'flash.passkey_added', ['name' => $credential->name]);

        return $this->json($response, ['redirect' => self::TWO_STEP]);
    }

    public function renamePasskey(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $body = $this->body($request);

        try {
            $this->webAuthn->rename(
                $this->user($request),
                (int) $id,
                is_scalar($body['name'] ?? null) ? (string) $body['name'] : '',
            );
            $this->flash('success', 'flash.passkey_renamed');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->redirectAfterWrite($request, $response, self::TWO_STEP);
    }

    public function revokePasskey(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $body = $this->body($request);

        try {
            $this->webAuthn->revoke(
                $this->user($request),
                (int) $id,
                is_scalar($body['password'] ?? null) ? (string) $body['password'] : '',
                $this->clientIp($request),
            );
            $this->flash('success', 'flash.passkey_revoked');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->redirectAfterWrite($request, $response, self::TWO_STEP);
    }

    public function revokeSession(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $handle = is_scalar($body['handle'] ?? null) ? (string) $body['handle'] : '';

        $revoked = $this->sessions->revoke($this->user($request), $handle, $this->session->id());

        $this->flash(
            $revoked ? 'success' : 'error',
            $revoked ? 'flash.session_revoked' : 'flash.session_already_gone',
        );

        return $this->redirectAfterWrite($request, $response, self::SESSIONS);
    }

    public function revokeOtherSessions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $count = $this->sessions->revokeAllOthers($this->user($request), $this->session->id());

        $this->flash('success', 'flash.other_sessions_revoked', ['count' => $count]);

        return $this->redirectAfterWrite($request, $response, self::SESSIONS);
    }

    /**
     * An inline SVG QR code.
     *
     * SVG rather than a raster image so there is no dependency on ext-gd, and
     * inline rather than a URL so the secret is never fetched as a separate
     * request that could end up in a proxy log.
     */
    private function qrCode(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));

        return $writer->writeString($uri);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        return $this->jsonRaw($response, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function jsonRaw(ResponseInterface $response, string $json): ResponseInterface
    {
        $response->getBody()->write($json);

        return $response->withHeader('Content-Type', 'application/json');
    }
}
