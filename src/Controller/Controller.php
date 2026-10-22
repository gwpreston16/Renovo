<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Entity\User;
use App\Security\Scope;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\ValidationError;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Shared plumbing for controllers: rendering, redirecting, flash messages and
 * reading the request attributes the middleware stack put there.
 *
 * Controllers stay thin — they translate HTTP into a service call and back
 * again. Anything that decides something belongs in a service.
 */
abstract class Controller
{
    public function __construct(
        protected readonly Twig $view,
        protected readonly SessionInterface $session,
        protected readonly Translator $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $data = [],
    ): ResponseInterface {
        return $this->view->render($response, $template, $this->decorate($request, $data));
    }

    /**
     * Add the values every template expects: who is signed in, what they may
     * do, and any flash messages waiting to be shown.
     *
     * Doing it here rather than in each controller means a new page cannot
     * forget the navigation bar's context.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function decorate(ServerRequestInterface $request, array $data): array
    {
        return $data + [
            'current_user' => $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_USER),
            'scope' => $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_SCOPE),
            'flashes' => $this->session->isStarted() ? $this->session->consumeFlashes() : [],
            'current_path' => $request->getUri()->getPath(),
        ];
    }

    /**
     * Render a full page, or just its fragment when htmx asked for one.
     *
     * @param array<string, mixed> $data
     */
    protected function renderMaybeFragment(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $fullTemplate,
        string $fragmentTemplate,
        array $data = [],
    ): ResponseInterface {
        $template = $this->isHtmx($request) ? $fragmentTemplate : $fullTemplate;

        return $this->view->render($response, $template, $this->decorate($request, $data));
    }

    /**
     * Whether htmx asked for a fragment.
     *
     * Not when it is restoring history: after a `hx-push-url` swap, going
     * Back to a page htmx has no snapshot of makes it re-fetch the URL with
     * both headers and put the response in place of the whole body. A
     * fragment there would leave the page as one card.
     */
    protected function isHtmx(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('HX-Request') === 'true'
            && $request->getHeaderLine('HX-History-Restore-Request') !== 'true';
    }

    protected function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    /**
     * Redirect in a way htmx also honours, so a POST made from a fragment
     * navigates the browser instead of swapping a whole page into a corner.
     */
    protected function redirectAfterWrite(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $location,
    ): ResponseInterface {
        if ($this->isHtmx($request)) {
            return $response->withHeader('HX-Redirect', $location)->withStatus(204);
        }

        return $this->redirect($response, $location);
    }

    /**
     * Where an action on one subscription sends the member back to: its edit
     * page when the form said it came from there, otherwise its cost page.
     *
     * The price-change, attachment and cancel controls appear on both pages.
     * Only these two exact paths are honoured — never an arbitrary one — so
     * the field can never become a redirect to somewhere unexpected.
     */
    protected function subscriptionReturn(ServerRequestInterface $request, int $id, string $fallback = ''): string
    {
        $body = $this->body($request);
        $target = is_scalar($body['return_to'] ?? null) ? (string) $body['return_to'] : '';
        $edit = '/subscriptions/' . $id . '/edit';
        $money = '/subscriptions/' . $id . '/money';

        return match ($target) {
            // The cost page's Payments card has its own section id, so a
            // write made from it lands back on it.
            $edit, $money, $money . '#payments' => $target,
            default => $fallback !== '' ? $fallback : $money,
        };
    }

    protected function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_USER);
        if (!$user instanceof User) {
            throw new \RuntimeException('This route requires authentication middleware.');
        }

        return $user;
    }

    protected function scope(ServerRequestInterface $request): Scope
    {
        $scope = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_SCOPE);
        if (!$scope instanceof Scope) {
            throw new \RuntimeException('This route requires authentication middleware.');
        }

        return $scope;
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * Queue a message for the page the user lands on next.
     *
     * The argument is a translation key, not a sentence: see
     * SessionInterface::flash().
     *
     * @param array<string, string|int|float> $parameters
     */
    protected function flash(string $type, string $key, array $parameters = []): void
    {
        $this->session->flash($type, $key, $parameters);
    }

    /**
     * Queue every message from a validation failure.
     *
     * One flash per field rather than one line with them all run together:
     * they are separate sentences about separate fields, and a reader picking
     * their way through "Enter a name. Choose a currency." has to do the
     * splitting themselves.
     */
    protected function flashErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $error) {
            $this->flash('error', $error->key, $error->parameters);
        }
    }

    /**
     * A validation failure as one sentence, for the handful of routes that
     * answer a `fetch()` with JSON rather than re-rendering a form — the
     * passkey ceremonies, which have no form to re-render.
     */
    protected function errorSentence(ValidationException $exception): string
    {
        return implode(' ', array_map(
            fn (ValidationError $error): string => $this->translator->trans($error->key, $error->parameters),
            $exception->errors(),
        ));
    }

    protected function notFound(ServerRequestInterface $request): HttpNotFoundException
    {
        return new HttpNotFoundException($request);
    }

    protected function clientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? '';

        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }
}
