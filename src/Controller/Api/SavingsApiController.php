<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Api\Resource;
use App\Application\Middleware\TokenAuthenticationMiddleware;
use App\Domain\Entity\ApiToken;
use App\I18n\Translator;
use App\Service\CatchUpService;
use App\Service\SavingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Domain\TokenScope;

/**
 * What offers and cancellations have saved: the Analytics card's two figures
 * and the subscriptions behind them, as `SavingsService` works them out for
 * the token's scope — the household's, or in ISOLATED mode the token holder's
 * own share.
 */
final class SavingsApiController extends ApiController
{
    public function __construct(
        Translator $translator,
        private readonly SavingsService $savings,
        private readonly CatchUpService $catchUp,
    ) {
        parent::__construct($translator);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        // The catch-up the charge list runs, for a token that may write, so
        // the offers' charges that have fallen due are counted.
        $token = $request->getAttribute(TokenAuthenticationMiddleware::ATTRIBUTE_TOKEN);
        if ($token instanceof ApiToken && $token->scopes->allows(TokenScope::SubscriptionsWrite)) {
            $this->catchUp->run($scope);
        }

        return $this->json($response, ['data' => Resource::savings($this->savings->forScope($scope))]);
    }
}
