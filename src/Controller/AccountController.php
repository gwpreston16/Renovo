<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Http\AttachmentResponse;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\AccountService;
use App\Service\AvatarService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * The signed-in member's own account: their name, address, password and face.
 *
 * Writes only. The page these forms sit on is `/profile`, rendered by
 * `ProfileController`, and each of them posts back to it — so this class has
 * no `index()` and the template it used to render is gone.
 *
 * Alongside `ProfileController` rather than inside it, because the two answer
 * different questions about the same page. Preferences decide how Renovo looks
 * to one person; these decide who that person is and how they get in. Neither
 * needs a permission, for the same reason: every route here acts on
 * `$this->user($request)` and there is no parameter that could make it act on
 * anybody else.
 */
final class AccountController extends Controller
{
    private const DETAILS = '/profile#details';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly AccountService $account,
        private readonly AvatarService $avatars,
        // No AvatarStorage here any more: the only thing it answered was the
        // size hint on the picture card, and the card is rendered by
        // ProfileController now. The uploads themselves go through
        // AvatarService, which does its own limit checking.
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($view, $session, $translator);
    }

    /**
     * Where the account page used to be.
     *
     * Kept as a redirect rather than removed: the confirmation email for an
     * address change links here, and those are sent before anybody rearranges
     * a screen. Nothing else should point at it — every link in the
     * application now names `/profile` — so this exists for the mail already
     * in somebody's inbox and for a bookmark.
     */
    public function moved(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirect($response, self::DETAILS);
    }

    /**
     * "Who you are": the name and the address, one form and one button.
     *
     * The address field is absent for an account with no mailbox of its own,
     * which the service reads as "leave it", and holds the current address
     * otherwise, which it reads the same way.
     */
    public function updateDetails(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $requested = $this->account->updateDetails(
                $this->user($request),
                is_scalar($body['display_name'] ?? null) ? (string) $body['display_name'] : '',
                is_scalar($body['email'] ?? null) ? (string) $body['email'] : null,
                is_scalar($body['current_password'] ?? null) ? (string) $body['current_password'] : '',
                $this->clientIp($request),
            );
            $this->flash('success', $requested ? 'flash.email_change_requested' : 'flash.details_saved');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->done($request, $response);
    }

    public function resendEmailChange(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        try {
            $this->account->resendEmailChange($this->user($request));
            $this->flash('success', 'flash.email_change_resent');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->done($request, $response);
    }

    public function cancelEmailChange(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $this->account->cancelEmailChange($this->user($request));
        $this->flash('success', 'flash.email_change_cancelled');

        return $this->done($request, $response);
    }

    public function updatePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $revoked = $this->account->changePassword(
                $this->user($request),
                is_scalar($body['current_password'] ?? null) ? (string) $body['current_password'] : '',
                is_scalar($body['password'] ?? null) ? (string) $body['password'] : '',
                is_scalar($body['password_confirm'] ?? null) ? (string) $body['password_confirm'] : '',
                ($body['sign_out_others'] ?? '') === '1',
                $this->clientIp($request),
            );

            $this->flash(
                'success',
                $revoked > 0 ? 'flash.password_changed_sessions' : 'flash.password_changed',
                $revoked > 0 ? ['count' => $revoked] : [],
            );
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->done($request, $response);
    }

    public function uploadAvatar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $file = $request->getUploadedFiles()['avatar'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            $this->flash('error', 'error.file.required');

            return $this->done($request, $response);
        }

        try {
            $this->account->setAvatar($this->user($request), $file);
            $this->flash('success', 'flash.avatar_saved');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->done($request, $response);
    }

    public function removeAvatar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $removed = $this->account->removeAvatar($this->user($request));

        $this->flash($removed ? 'success' : 'error', $removed ? 'flash.avatar_removed' : 'flash.avatar_missing');

        return $this->done($request, $response);
    }

    /**
     * Stream a member's picture.
     *
     * A 404 for somebody outside the household, not a 403: whether an account
     * exists and whether it has a picture are both things a stranger has no
     * business learning, and two different answers would tell them.
     */
    public function avatar(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $path = $this->avatars->pathFor($this->user($request), (int) $id);

        if ($path === null) {
            throw $this->notFound($request);
        }

        return AttachmentResponse::streamImage($response, $this->streams, $path, 'image/png');
    }

    private function done(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirectAfterWrite($request, $response, '/profile');
    }
}
