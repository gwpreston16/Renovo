<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Api\Resource;
use App\Application\Middleware\TokenAuthenticationMiddleware;
use App\Domain\Entity\ApiToken;
use App\Domain\Entity\Charge;
use App\I18n\Translator;
use App\Service\CatchUpService;
use App\Service\ChargeService;
use App\Service\SubscriptionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use App\Domain\TokenScope;

/**
 * The payment ledger over the API: a subscription's charges, a correction to
 * one, and a charge added by hand.
 *
 * A subscription or charge that does not exist and one that is not this
 * token's to see are the same 404, as on every other sub-resource. A charge
 * the token can see but not change — a Contributor's view of another
 * member's row — is a 404 to PATCH as well: what it cannot write is, for the
 * write, not there.
 */
final class ChargeApiController extends ApiController
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        Translator $translator,
        private readonly ChargeService $charges,
        private readonly SubscriptionService $subscriptions,
        private readonly CatchUpService $catchUp,
    ) {
        parent::__construct($translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);

        // The catch-up the subscription list runs, for a token that may write,
        // so a client reading only this endpoint sees every passed date.
        $token = $request->getAttribute(TokenAuthenticationMiddleware::ATTRIBUTE_TOKEN);
        if ($token instanceof ApiToken && $token->scopes->allows(TokenScope::SubscriptionsWrite)) {
            $this->catchUp->run($scope);
        }
        if ($this->subscriptions->find($scope, (int) $id) === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        $query = $request->getQueryParams();
        $perPage = $this->positive($query['per_page'] ?? null, self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE);
        $number = $this->positive($query['page'] ?? null, 1, PHP_INT_MAX);
        $page = $this->charges->page($scope, (int) $id, $number, $perPage);

        return $this->json($response, [
            'data' => array_map(
                static fn (Charge $charge): array => Resource::charge($charge),
                $page['charges'],
            ),
            'meta' => [
                'page' => $page['page'],
                'per_page' => $perPage,
                'total' => $page['total'],
                'total_pages' => (int) ceil($page['total'] / $perPage),
            ],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        if ($this->subscriptions->find($scope, (int) $id) === null || !$this->charges->mayAddTo($scope, (int) $id)) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        // Minor units only, as every price over the API is: the web form's
        // decimal `amount` is not read here, and a missing `amount_minor` is
        // reported under its own name.
        $payload = $this->payload($request);
        unset($payload['amount']);
        $payload['amount_minor'] ??= null;

        $charge = $this->charges->addManual($scope, (int) $id, $payload);

        return $this->json($response, ['data' => Resource::charge($charge)], 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        $charge = $this->charges->find($scope, (int) $id);
        if ($charge === null || !$this->charges->mayChange($scope, $charge)) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.charge_not_found'));
        }

        return $this->json($response, [
            'data' => Resource::charge($this->charges->patch($scope, $charge->id, $this->payload($request))),
        ]);
    }

    private function positive(mixed $raw, int $default, int $max): int
    {
        if (!is_scalar($raw) || !is_numeric($raw) || (int) $raw < 1) {
            return $default;
        }

        return min((int) $raw, $max);
    }
}
