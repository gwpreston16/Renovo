<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Application\Middleware\TokenAuthenticationMiddleware;
use App\Domain\Entity\ApiToken;
use App\Domain\Entity\User;
use App\I18n\Translator;
use App\Security\Scope;
use App\Service\ValidationError;
use App\Service\ValidationException;
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
 * directory that the browser does not also get, with one exception: a list's
 * query string. A client is told when a filter or a page isn't one the list
 * accepts (a 422), where the browser's lists quietly ignore it (K193, K256).
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
     * an object — malformed, a list or scalar, or sent with no Content-Type or
     * one that is not JSON, multipart or form — is a 400 rather than an empty
     * object, so the client is told what is wrong instead of that a field is
     * missing or that nothing was saved. An empty body, `{}` and a multipart or
     * form body keep their present path.
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
        // Multipart and form bodies keep their path; any other type that
        // carries a body (text/plain, application/xml, text/json) cannot be
        // read as an object, so it is refused rather than taken as empty.
        $isFormLike = $type === 'application/x-www-form-urlencoded' || str_starts_with($type, 'multipart/');
        if (!$isFormLike && trim($this->rawBody($request)) !== '') {
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

    /**
     * Refuses a `page` or `per_page` that is sent and isn't a whole number of 1
     * or more, as a 422 naming the parameter. An empty value means the default.
     *
     * Every paged list on the API calls this, so they answer the same input the
     * same way: a forgiving read used to turn `?page=0` into page 1 on the
     * charges list while the subscription list refused it (K256, K270). The
     * browser's lists stay forgiving; a person editing an address bar is not a
     * client that needs telling.
     *
     * @param array<string, mixed> $query
     *
     * @throws ValidationException
     */
    protected function assertWholePaging(array $query): void
    {
        $errors = [];
        foreach (['page', 'per_page'] as $name) {
            $value = $query[$name] ?? '';
            if ($value !== '' && !self::isPositiveWholeNumber($value)) {
                $errors[$name] = self::notAWholeNumber();
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Digits only, and not zero: no sign, no decimal point, no spaces, and not
     * a list (`page[]=1`). A value too large for an int is still a whole
     * number; as an id it matches no row, and as a page it is past the end.
     */
    protected static function isPositiveWholeNumber(mixed $value): bool
    {
        return is_string($value) && ctype_digit($value) && ltrim($value, '0') !== '';
    }

    /**
     * The 422 a parameter gets when it isn't a whole number of 1 or more.
     */
    protected static function notAWholeNumber(): ValidationError
    {
        return new ValidationError('error.api.filter_invalid', ['allowed' => 'a whole number of 1 or more']);
    }
}
