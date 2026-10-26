<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\I18n\Translator;
use App\Controller\Controller;
use App\Domain\AuditAction;
use App\Repository\AuthAttemptRepository;
use App\Security\SafeRedirectTarget;
use App\Security\SessionInterface;
use App\Service\Auth\SignInService;
use App\Service\Auth\WebAuthnService;
use App\Service\AuditLogService;
use App\Service\RateLimiter;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Signing in with a passkey and nothing else.
 *
 * No email address is asked for and none is sent: the credential the browser
 * presents identifies the account, and the assertion proves possession of it.
 * This is a first-class login method, not a shortcut around the password — the
 * ceremony is the same one used as a second factor and is verified identically.
 *
 * Note that a failure here is throttled per IP with an empty account key. There
 * is no address to attribute it to until a credential verifies, and inventing
 * one from the credential the client claims would let an attacker lock a chosen
 * account out by presenting junk assertions against it.
 */
final class PasskeyLoginController extends Controller
{
    private const CHALLENGE_SESSION_KEY = 'passkey_login_options';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly WebAuthnService $webAuthn,
        private readonly SignInService $signIn,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLogService $audit,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function options(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $remaining = $this->rateLimiter->remainingLockoutSeconds(
            AuthAttemptRepository::KIND_LOGIN,
            '',
            $this->clientIp($request),
        );

        if ($remaining > 0) {
            return $this->json(
                $response->withStatus(429),
                ['error' => $this->translator->trans('error.auth.throttled_short')],
            );
        }

        // No user: the allow-list is empty and the browser offers whichever
        // discoverable credential it holds for this relying party.
        $options = $this->webAuthn->serializeOptions($this->webAuthn->authenticationOptions());
        $this->session->set(self::CHALLENGE_SESSION_KEY, $options);

        return $this->jsonRaw($response, $options);
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $stored = $this->session->get(self::CHALLENGE_SESSION_KEY);
        if (!is_string($stored) || $stored === '') {
            return $this->json($response->withStatus(400), ['error' => 'Start again — that challenge has expired.']);
        }

        try {
            $result = $this->webAuthn->completeAuthentication(
                (string) $request->getBody(),
                $this->webAuthn->deserializeRequestOptions($stored),
            );
        } catch (ValidationException $exception) {
            $this->rateLimiter->recordFailure(AuthAttemptRepository::KIND_LOGIN, '', $this->clientIp($request));

            $this->audit->recordAnonymous(AuditAction::LoginFailed, 'passkey', null, ['method' => 'passkey']);

            return $this->json($response->withStatus(422), ['error' => $this->errorSentence($exception)]);
        } finally {
            $this->session->remove(self::CHALLENGE_SESSION_KEY);
        }

        $user = $result['user'];

        if ($user->isDisabled()) {
            // A passkey is a credential the account still holds, and it still
            // verifies — which is exactly why this check has to be here and not
            // only on the password path. Nothing about the credential is wrong;
            // the account is closed.
            $this->audit->recordAnonymous(AuditAction::LoginFailed, $user->email, $user, ['reason' => 'disabled']);

            return $this->json($response->withStatus(403), [
                'error' => $this->translator->trans('error.auth.disabled'),
            ]);
        }

        if (!$user->isVerified()) {
            return $this->json($response->withStatus(403), [
                'error' => $this->translator->trans('error.auth.unverified_short'),
            ]);
        }

        $this->rateLimiter->recordSuccess(
            AuthAttemptRepository::KIND_LOGIN,
            $user->email,
            $this->clientIp($request),
        );

        // The body is the authenticator's response, so the sign-in form's
        // "Keep me signed in" rides on the query string beside `next`.
        $remember = ($request->getQueryParams()['remember'] ?? '1') !== '0';

        $this->signIn->establish($user, SignInService::METHOD_PASSKEY, $remember);

        $this->flash('success', 'flash.welcome_back', ['name' => $user->displayName]);

        return $this->json($response, [
            'redirect' => $this->signIn->landingFor($user, $this->safeTarget($request)),
        ]);
    }

    private function safeTarget(ServerRequestInterface $request): string
    {
        $params = $request->getQueryParams();
        $candidate = is_string($params['next'] ?? null) ? $params['next'] : '/';

        return SafeRedirectTarget::orHome($candidate);
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
