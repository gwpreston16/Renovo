<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Application\Middleware\TokenAuthenticationMiddleware;
use App\Domain\Entity\ApiToken;
use App\Domain\Entity\User;
use App\I18n\Translator;
use App\Security\Scope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Exception\HttpBadRequestException;

/**
 * Shared plumbing for API endpoints: reading a JSON body, writing a JSON
 * response, and the same two request attributes the web controllers read.
 *
 * Endpoints stay as thin as the web controllers and for the same reason — they
 * call the identical services. There is no rule, validation or default in this
 * directory that the browser does not also get.
 *
 * Route placeholders arrive as named arguments — `{id}` reaches a method
 * declaring `string $id` — because the application sets Slim's
 * RequestResponseNamedArgs strategy in config/bootstrap.php. A method taking an
 * `array $args` instead is a TypeError at request time, not a compile error, so
 * it is worth knowing before writing one.
 */
abstract class ApiController
{
    public function __construct(protected readonly Translator $translator)
    {
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    protected function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    protected function noContent(ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(204);
    }

    /**
     * The decoded request body.
     *
     * Slim's body-parsing middleware has already turned `application/json` into
     * an array by the time this runs. A JSON write whose body cannot be read as
     * an object — malformed, a list or scalar, or sent without a Content-Type —
     * is a 400 rather than an empty object, so the client is told what is wrong
     * instead of that a field is missing or that nothing was saved. An empty
     * body, `{}` and a multipart or form body keep their present path.
     *
     * @return array<string, mixed>
     */
    protected function payload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        if (is_array($body)) {
            if ($body !== [] && array_is_list($body)) {
                throw $this->unreadableBody($request);
            }

            return $body;
        }

        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        $readsAsJson = $type === '' || $type === 'application/json' || str_ends_with($type, '+json');
        if ($readsAsJson && trim($this->rawBody($request)) !== '') {
            throw $this->unreadableBody($request);
        }

        return [];
    }

    private function rawBody(ServerRequestInterface $request): string
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return (string) $stream;
    }

    private function unreadableBody(ServerRequestInterface $request): HttpBadRequestException
    {
        return new HttpBadRequestException($request, $this->translator->trans('error.api.body_not_object'));
    }

    protected function token(ServerRequestInterface $request): ApiToken
    {
        $token = $request->getAttribute(TokenAuthenticationMiddleware::ATTRIBUTE_TOKEN);
        if (!$token instanceof ApiToken) {
            throw new RuntimeException('This route requires token authentication middleware.');
        }

        return $token;
    }

    protected function scope(ServerRequestInterface $request): Scope
    {
        $scope = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_SCOPE);
        if (!$scope instanceof Scope) {
            throw new RuntimeException('This route requires token authentication middleware.');
        }

        return $scope;
    }

    protected function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_USER);
        if (!$user instanceof User) {
            throw new RuntimeException('This route requires token authentication middleware.');
        }

        return $user;
    }
}
