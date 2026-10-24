<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\I18n\Translator;
use App\Application\Api\Resource;
use App\Application\Api\SubscriptionPayload;
use App\Application\Middleware\TokenAuthenticationMiddleware;
use App\Domain\Entity\ApiToken;
use App\Domain\SubscriptionFilter;
use App\Security\Scope;
use App\Domain\Entity\Subscription;
use App\Service\CatchUpService;
use App\Service\LogoStorage;
use App\Service\PriceHistoryService;
use App\Service\SubscriptionService;
use App\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use App\Domain\TokenScope;

/**
 * Subscriptions over HTTP as JSON.
 *
 * Every method here is the web controller's method with a different renderer.
 * They share `SubscriptionService`, so the billing-cycle rules, the trial
 * checks, the ISOLATED-mode owner override and the price-history write all
 * happen identically whichever door the request came through — which is the
 * property that makes the API worth having rather than a second implementation
 * to keep in step.
 *
 * There is no PATCH. See SubscriptionPayload for why: the service's input is
 * absence-sensitive, so a partial body is the one shape that could half-write a
 * row. PUT replaces, and a client changing one field GETs, edits and PUTs back.
 */
final class SubscriptionApiController extends ApiController
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        Translator $translator,
        private readonly SubscriptionService $subscriptions,
        private readonly PriceHistoryService $priceHistory,
        private readonly LogoStorage $logos,
        private readonly CatchUpService $catchUp,
        private readonly Clock $clock,
    ) {
        parent::__construct($translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $query = $request->getQueryParams();

        // The same lazy catch-up the web list performs. Without it a client
        // polling this endpoint would read a next-payment date in the past —
        // and a negative days-until — until somebody happened to open the
        // subscriptions page in a browser, which is precisely the kind of
        // divergence between the two doors that this phase exists to avoid.
        $this->catchUp($request, $scope);

        $filter = SubscriptionFilter::fromQueryParams($query);
        $perPage = $this->perPage($query);
        $filter = new SubscriptionFilter(
            search: $filter->search,
            categoryId: $filter->categoryId,
            tagIds: $filter->tagIds,
            ownerUserId: $filter->ownerUserId,
            currency: $filter->currency,
            type: $filter->type,
            includeInactive: $filter->includeInactive,
            sort: $filter->sort,
            direction: $filter->direction,
            page: $filter->page,
            perPage: $perPage,
            status: $filter->status,
        );

        $today = $this->clock->today();
        $total = $this->subscriptions->count($scope, $filter);
        $promotions = $this->priceHistory->promotions($scope);

        return $this->json($response, [
            'data' => array_map(
                fn ($subscription): array => Resource::subscription(
                    $subscription,
                    $today,
                    $promotions[$subscription->id] ?? null,
                ),
                $this->subscriptions->list($scope, $filter),
            ),
            'meta' => [
                'page' => $filter->page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ]);
    }

    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $subscription = $this->subscriptions->find($scope, (int) $id);
        if ($subscription === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        return $this->json($response, ['data' => $this->resource($scope, $subscription)]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);

        $id = $this->subscriptions->create(
            $scope,
            SubscriptionPayload::toServiceInput($this->payload($request)),
        );

        $created = $this->subscriptions->find($scope, $id);
        if ($created === null) {
            // Only reachable if the row vanished between the two statements.
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_unreadable'));
        }

        return $this->json(
            $response,
            ['data' => $this->resource($scope, $created)],
            201,
        )->withHeader('Location', sprintf('/api/v1/subscriptions/%d', $id));
    }

    /**
     * Replace a subscription.
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $id = (int) $id;

        $existing = $this->subscriptions->find($scope, $id);
        if ($existing === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        $this->subscriptions->update(
            $scope,
            $id,
            SubscriptionPayload::toServiceInput($this->payload($request), $existing),
        );

        $updated = $this->subscriptions->find($scope, $id);
        if ($updated === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        return $this->json($response, ['data' => $this->resource($scope, $updated)]);
    }

    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $id = (int) $id;

        if ($this->subscriptions->find($scope, $id) === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        $this->subscriptions->delete($scope, $id);

        return $this->noContent($response);
    }

    /**
     * Cancel: finished, as of today. `cancelled_at` is read-only in the
     * payload, so this and `uncancel` are the only ways to move it.
     */
    public function cancel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        return $this->changeCancellation($request, $response, (int) $id, true);
    }

    /**
     * Undo a cancel. The subscription returns to paused, not active.
     */
    public function uncancel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        return $this->changeCancellation($request, $response, (int) $id, false);
    }

    private function changeCancellation(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        bool $cancel,
    ): ResponseInterface {
        $scope = $this->scope($request);

        if ($this->subscriptions->find($scope, $id) === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        // A row the caller can see but not change — a split participant's, a
        // Contributor looking at somebody else's — throws a scope violation
        // here, which the error handler answers as 404 like every other write.
        $cancel ? $this->subscriptions->cancel($scope, $id) : $this->subscriptions->uncancel($scope, $id);

        $updated = $this->subscriptions->find($scope, $id);
        if ($updated === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        return $this->json($response, ['data' => $this->resource($scope, $updated)]);
    }

    /**
     * Replace a subscription's logo.
     *
     * Its own endpoint rather than a field on the resource, because it is a
     * file. The path is chosen by LogoStorage from the detected image type, so
     * a client cannot name it, and the JSON representation reports it read-only.
     */
    public function uploadLogo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $id = (int) $id;

        $existing = $this->subscriptions->find($scope, $id);
        if ($existing === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        $file = $request->getUploadedFiles()['logo'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new HttpBadRequestException($request, 'Send the image as multipart form data in a "logo" part.');
        }

        $stored = $this->logos->store($file);
        $this->subscriptions->setLogo($scope, $id, $stored);

        if ($existing->logoPath !== null && $existing->logoPath !== $stored) {
            $this->logos->delete($existing->logoPath);
        }

        $updated = $this->subscriptions->find($scope, $id);
        if ($updated === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        return $this->json($response, ['data' => $this->resource($scope, $updated)]);
    }

    public function deleteLogo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $id = (int) $id;

        $existing = $this->subscriptions->find($scope, $id);
        if ($existing === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        $this->subscriptions->setLogo($scope, $id, null);
        $this->logos->delete($existing->logoPath);

        return $this->noContent($response);
    }

    /**
     * Roll overdue recurring subscriptions forward, if this caller may.
     *
     * Two conditions, not one. The service already refuses to write for a role
     * that cannot — a Viewer's GET never advances anything. The extra check
     * here is on the *token*: a read-only token performing a write, even a
     * housekeeping one nobody asked for, is not what "read-only" means to the
     * person who chose it.
     */
    /**
     * @return array<string, mixed>
     */
    private function resource(Scope $scope, Subscription $subscription): array
    {
        return Resource::subscription(
            $subscription,
            $this->clock->today(),
            $this->priceHistory->promotionFor($scope, $subscription->id),
        );
    }

    /**
     * The whole catch-up, in its order — prices, then trials, then payments —
     * as every web page runs it. Payments alone would roll a trial's
     * conversion date past before converting it, and write its first charge
     * to the ledger at £0.
     */
    private function catchUp(ServerRequestInterface $request, Scope $scope): void
    {
        $token = $request->getAttribute(TokenAuthenticationMiddleware::ATTRIBUTE_TOKEN);

        if ($token instanceof ApiToken && $token->scopes->allows(TokenScope::SubscriptionsWrite)) {
            $this->catchUp->run($scope);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function perPage(array $query): int
    {
        $raw = $query['per_page'] ?? null;
        if (!is_scalar($raw)) {
            return SubscriptionFilter::DEFAULT_PER_PAGE;
        }

        $value = (int) $raw;

        return $value > 0 ? min($value, self::MAX_PER_PAGE) : SubscriptionFilter::DEFAULT_PER_PAGE;
    }
}
