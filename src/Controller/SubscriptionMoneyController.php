<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Repository\MembershipRepository;
use App\Security\SessionInterface;
use App\Service\AttachmentService;
use App\Service\CatchUpService;
use App\Service\FixedTermService;
use App\Service\ChargeService;
use App\Service\PriceHistoryService;
use App\Service\ScenarioService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Service\UsageService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The money-related actions that hang off a single subscription: its price
 * trend, scheduling a future price, how the cost is split, and recording use.
 *
 * Kept apart from SubscriptionController so that neither ends up as a
 * grab-bag — that one does CRUD, this one does the Phase 2 additions. Both are
 * thin, and every decision below belongs to a service.
 */
final class SubscriptionMoneyController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly SubscriptionService $subscriptions,
        private readonly PriceHistoryService $priceHistory,
        private readonly SplitService $splits,
        private readonly UsageService $usage,
        private readonly MembershipRepository $memberships,
        private readonly AttachmentService $attachments,
        private readonly ScenarioService $scenarios,
        private readonly ChargeService $charges,
        private readonly CatchUpService $catchUp,
        private readonly FixedTermService $terms,
    ) {
        parent::__construct($view, $session, $translator);
    }

    /**
     * Price trend plus the split arrangement — one page, because they are the
     * two questions people have about what a subscription costs them.
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);

        // The same lazy catch-up as the list, so the Payments card shows every
        // billing date that has passed — and a no-op for a Viewer.
        $this->catchUp->run($scope);

        $subscription = $this->subscriptions->find($scope, (int) $id);

        if ($subscription === null) {
            // Out of scope and non-existent are the same answer, as everywhere.
            throw $this->notFound($request);
        }

        $participants = $this->splits->participants($scope, $subscription->id);
        $page = $request->getQueryParams()['payments_page'] ?? '1';

        return $this->render($request, $response, 'subscriptions/money.twig', [
            'subscription' => $subscription,
            'trend' => $this->priceHistory->trendFor($scope, $subscription->id),
            'next_change' => $this->priceHistory->nextScheduledChange($scope, $subscription->id),
            'promotion' => $this->priceHistory->promotionFor($scope, $subscription->id),
            'participants' => $participants,
            'shares' => $this->splits->sharesOf($subscription, $participants),
            'can_edit_split' => $this->splits->canEdit($scope, $subscription),
            'members' => $scope->hasHousehold()
                ? $this->memberships->findMembersOfHousehold((int) $scope->householdId)
                : [],
            'max_rating' => UsageService::MAX_RATING,
            'can_plan' => $this->scenarios->isPlannable($subscription),
            'attachments' => $this->attachments->forSubscription($scope, $subscription->id),
            'payments' => $this->charges->page($scope, $subscription->id, is_numeric($page) ? (int) $page : 1),
            'term' => $this->terms->progress($scope, $subscription),
            'invoices' => $this->attachments->byCharge($scope, $subscription->id),
            'today' => $this->charges->today(),
            'errors' => [],
        ]);
    }

    public function schedulePriceChange(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        try {
            $this->priceHistory->schedule($this->scope($request), (int) $id, $this->body($request));
        } catch (ValidationException $exception) {
            return $this->redirectWithErrors($request, $response, (int) $id, $exception);
        }

        $this->flash('success', 'flash.price_change_scheduled');

        return $this->redirectAfterWrite($request, $response, $this->subscriptionReturn($request, (int) $id));
    }

    public function updateSplit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        try {
            $this->splits->update($this->scope($request), (int) $id, $this->body($request));
        } catch (ValidationException $exception) {
            return $this->redirectWithErrors($request, $response, (int) $id, $exception);
        }

        $this->flash('success', 'flash.split_updated');

        return $this->redirectAfterWrite($request, $response, '/subscriptions/' . (int) $id . '/money');
    }

    public function recordUse(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->usage->recordUse($this->scope($request), (int) $id);

        return $this->redirectAfterWrite($request, $response, $this->backTo($request, (int) $id));
    }

    public function rate(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $body = $this->body($request);
        $raw = trim(is_scalar($body['rating'] ?? null) ? (string) $body['rating'] : '');

        $this->usage->rate($this->scope($request), (int) $id, $raw === '' ? null : (int) $raw);

        return $this->redirectAfterWrite($request, $response, $this->backTo($request, (int) $id));
    }

    public function resetUsage(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->usage->reset($this->scope($request), (int) $id);
        $this->flash('success', 'flash.usage_reset');

        return $this->redirectAfterWrite($request, $response, $this->backTo($request, (int) $id));
    }

    /**
     * Where to go back to after a usage action.
     *
     * These controls appear on more than one page, so the form says where it
     * came from — but only a path within this application is honoured, never
     * an arbitrary URL, or the "return to" would be an open redirect.
     */
    private function backTo(ServerRequestInterface $request, int $id): string
    {
        $body = $this->body($request);
        $return = is_scalar($body['return_to'] ?? null) ? (string) $body['return_to'] : '';

        // A leading double slash is a protocol-relative URL pointing somewhere
        // else entirely, so a single leading slash is required and "//" is not.
        if (str_starts_with($return, '/') && !str_starts_with($return, '//')) {
            return $return;
        }

        return '/subscriptions/' . $id . '/money';
    }

    private function redirectWithErrors(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        ValidationException $exception,
    ): ResponseInterface {
        $this->flashErrors($exception);

        return $this->redirectAfterWrite($request, $response, $this->subscriptionReturn($request, $id));
    }
}
