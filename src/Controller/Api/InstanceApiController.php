<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\AppVersion;
use App\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Which release this instance runs.
 *
 * Any valid token may ask, as with `/me`: a client that knows the version can
 * tell which fields to expect. Nobody without a token can — the version is not
 * on `/healthz` or any signed-out page, since knowing exactly which release an
 * instance runs makes it easier to target its known issues.
 */
final class InstanceApiController extends ApiController
{
    public function __construct(
        Translator $translator,
        private readonly AppVersion $version,
    ) {
        parent::__construct($translator);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['data' => [
            'version' => $this->version->current(),
            'build' => $this->version->build(),
        ]]);
    }
}
