<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Security\SessionInterface;
use App\Application\Api\ApiPath;
use App\Security\ScopeViolationException;
use App\I18n\Translator;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Handlers\ErrorHandler;
use Slim\Views\Twig;
use Throwable;

/**
 * Renders errors as pages, fragments or JSON rather than stack traces.
 *
 * Two rules matter here, and they apply to every representation.
 *
 * A scope violation becomes a 404, not a 403, so that probing for another
 * household's row ids tells the prober nothing. This holds on the API as well:
 * an endpoint that answered 403 for "exists but not yours" and 404 for "no such
 * row" would be a working enumeration oracle, and being machine-readable would
 * make it a convenient one.
 *
 * And in production the message shown is always generic: the detail goes to the
 * log, where it is useful, rather than to the client, where it is a disclosure.
 * The exception is a validation failure, whose field messages are the entire
 * point of returning it.
 */
final class HttpErrorHandler extends ErrorHandler
{
    /**
     * The form's key for a field the API names differently. The service reports
     * against the form's keys; an API client is told about the field it sent
     * (K41).
     */
    private const API_FIELD_NAMES = [
        'price' => 'price_minor',
        'converts_to_price' => 'converts_to_price_minor',
    ];

    /**
     * The form's message for a price it can't read asks for a decimal; the
     * API takes minor units, so its client is asked for those instead
     * (K161), on the same fields and beside every other field's error.
     */
    private const API_MESSAGE_KEYS = [
        'price' => ['error.price.invalid' => 'error.subscription.minor_invalid'],
        'converts_to_price' => ['error.trial.converts_to_invalid' => 'error.subscription.minor_invalid'],
    ];

    public function __construct(
        \Slim\Interfaces\CallableResolverInterface $callableResolver,
        \Psr\Http\Message\ResponseFactoryInterface $responseFactory,
        private readonly Twig $view,
        private readonly Translator $translator,
        private readonly bool $debug,
        ?LoggerInterface $logger = null,
        private readonly ?SessionInterface $session = null,
    ) {
        parent::__construct($callableResolver, $responseFactory, $logger);
    }

    protected function respond(): ResponseInterface
    {
        $exception = $this->exception;
        $status = 500;
        $title = $this->translator->trans('error.page.unexpected_title');
        $message = $this->translator->trans('error.page.unexpected_message');
        /** @var array<string, string> $fieldErrors */
        $fieldErrors = [];

        if ($exception instanceof ValidationException) {
            // Reached only from the API: a web controller catches this and
            // re-renders the form with the messages against their fields.
            //
            // The keys are resolved here rather than at the throw site, which
            // is what lets one service answer a browser and an API client in
            // whichever language each of them is being read in.
            $status = 422;
            $title = $this->translator->trans('error.page.validation_title');
            $message = $this->translator->trans('error.page.validation_message');

            $api = ApiPath::matches($this->request);
            foreach ($exception->errors() as $field => $error) {
                $key = $api ? (self::API_MESSAGE_KEYS[$field][$error->key] ?? $error->key) : $error->key;
                $fieldErrors[$field] = $this->translator->trans($key, $error->parameters);
            }
        } elseif ($exception instanceof ScopeViolationException) {
            // Indistinguishable from "no such row" on purpose.
            $status = 404;
            $title = $this->translator->trans('error.page.not_found_title');
            $message = $this->translator->trans('error.page.not_found_or_forbidden');
        } elseif ($exception instanceof HttpNotFoundException) {
            $status = 404;
            $title = $this->translator->trans('error.page.not_found_title');
            $message = $this->translator->trans('error.page.not_found_message');
        } elseif ($exception instanceof HttpForbiddenException) {
            $status = 403;
            $title = $this->translator->trans('error.page.forbidden_title');
            $message = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : $this->translator->trans('error.page.forbidden_message');
        } elseif ($exception instanceof HttpMethodNotAllowedException) {
            $status = 405;
            $title = $this->translator->trans('error.page.method_title');
            $message = $this->translator->trans('error.page.method_message');
        } elseif ($exception instanceof HttpException) {
            $status = $exception->getCode() >= 400 && $exception->getCode() < 600
                ? (int) $exception->getCode()
                : 400;
            $title = $exception->getTitle();
            $message = $exception->getMessage();
        }

        if ($this->displayErrorDetails && $status === 500) {
            $message = $exception->getMessage();
        }

        $response = $this->responseFactory->createResponse($status);

        if (ApiPath::matches($this->request)) {
            $renamed = [];
            foreach ($fieldErrors as $field => $error) {
                $renamed[self::API_FIELD_NAMES[$field] ?? $field] = $error;
            }

            return $this->respondWithJson($response, $status, $title, $message, $renamed);
        }

        // An htmx request gets a plain body: swapping a full error page into a
        // table fragment would leave the user looking at a nested layout.
        if ($this->request->getHeaderLine('HX-Request') === 'true') {
            $response->getBody()->write($title . ': ' . $message);

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $this->view->render($response, 'error.twig', [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'trace' => $this->debug ? $exception->getTraceAsString() : null,
            'flashes' => [],
            'current_user' => null,
            'scope' => null,
            /*
             * No mark, no instance name, no theme switch above the message.
             *
             * An error page is rendered with no user whoever is reading it:
             * the failure may have happened before the account was loaded, so
             * this handler cannot tell a signed-in reader's 404 from a
             * stranger's. The layout treats "no user" as "before there is an
             * account" and dresses the page accordingly, which for a signed-in
             * reader would mean offering them a theme switch that writes a
             * cookie none of their own pages read. This is the one page that
             * says no.
             */
            'auth_chrome' => false,
            /*
             * Which way back to offer. Not the account — see above — but
             * whether the session names one, which is a read of data already
             * in hand: "Back to sign in" to a stranger, "Back to the
             * dashboard" to somebody who is signed in and would otherwise be
             * sent to a sign-in form that bounces them straight home.
             */
            'signed_in' => $this->hasSignedInSession(),
            'current_path' => $this->request->getUri()->getPath(),
        ]);
    }

    private function hasSignedInSession(): bool
    {
        // Not gated on the session being open: SessionMiddleware writes and
        // closes it before this runs, and the data it read stays readable.
        return $this->session !== null
            && is_int($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
    }

    /**
     * The API's error envelope. Its shape is part of the published contract,
     * so it is described in the OpenAPI document and asserted by the contract
     * test — changing it here alone would fail CI, which is the intent.
     *
     * @param array<string, string> $fieldErrors
     */
    private function respondWithJson(
        ResponseInterface $response,
        int $status,
        string $title,
        string $message,
        array $fieldErrors,
    ): ResponseInterface {
        $body = ['error' => [
            'status' => $status,
            'title' => $title,
            'message' => $message,
        ]];

        if ($fieldErrors !== []) {
            $body['error']['errors'] = $fieldErrors;
        }

        $response->getBody()->write((string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
