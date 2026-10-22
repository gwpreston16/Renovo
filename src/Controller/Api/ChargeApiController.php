<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Api\Resource;
use App\Domain\Entity\Charge;
use App\I18n\Translator;
use App\Service\ChargeService;
use App\Service\SubscriptionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

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
    public function __construct(
        Translator $translator,
        private readonly ChargeService $charges,
        private readonly SubscriptionService $subscriptions,
    ) {
        parent::__construct($translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        if ($this->subscriptions->find($scope, (int) $id) === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        return $this->json($response, [
            'data' => array_map(
                static fn (Charge $charge): array => Resource::charge($charge),
                $this->charges->forSubscription($scope, (int) $id),
            ),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        if ($this->subscriptions->find($scope, (int) $id) === null || !$this->charges->mayAddTo($scope, (int) $id)) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        $charge = $this->charges->addManual($scope, (int) $id, $this->payload($request));

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
}
