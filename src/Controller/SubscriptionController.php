<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Domain\BillingCycle;
use App\Domain\Currency;
use App\Domain\NoticePeriod;
use App\Domain\SubscriptionFilter;
use App\Domain\SubscriptionStatus;
use App\Domain\SubscriptionType;
use App\Repository\MembershipRepository;
use App\Security\ScopeViolationException;
use App\Security\SessionInterface;
use App\Service\CategoryService;
use App\Service\PaymentMethodService;
use App\Service\LogoStorage;
use App\Service\BulkActionService;
use App\Service\CatchUpService;
use App\Service\SavedViewService;
use App\Service\AttachmentService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\SubscriptionExportService;
use App\Service\SubscriptionFormService;
use App\Service\SubscriptionScreenService;
use App\Service\SubscriptionService;
use App\Service\UserPreferencesService;
use App\Support\Clock;
use App\Service\TagService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * Subscription CRUD. Every mutating action here is additionally guarded by
 * RequirePermissionMiddleware on its route — see config/routes.php.
 */
final class SubscriptionController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly SubscriptionService $subscriptions,
        private readonly CategoryService $categories,
        private readonly PaymentMethodService $paymentMethods,
        private readonly TagService $tags,
        private readonly MembershipRepository $memberships,
        private readonly LogoStorage $logos,
        private readonly CatchUpService $catchUp,
        private readonly BulkActionService $bulkActions,
        private readonly SavedViewService $savedViews,
        private readonly SubscriptionScreenService $screen,
        private readonly SubscriptionFormService $form,
        private readonly SubscriptionExportService $export,
        private readonly SplitService $splits,
        private readonly PriceHistoryService $priceHistory,
        private readonly AttachmentService $attachments,
        private readonly UserPreferencesService $preferences,
        private readonly Clock $clock,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        // The list always shows paused subscriptions; the repository sinks them
        // to the bottom. There is no control for it any more, so the flag is
        // set here rather than read from the query string — the API keeps both
        // the parameter and its default.
        $filter = SubscriptionFilter::fromQueryParams($request->getQueryParams())->withIncludeInactive();
        $fragment = $this->isHtmx($request);

        // The sections around the list are computed for a whole page and not
        // for a filter keystroke. Both branches start by bringing the household
        // up to date — the overview's first act is that same catch-up — so the
        // list is always read after due price changes, ended trials and overdue
        // payment dates have been applied. What an htmx request skips is the
        // strip and the cancel-by card, which the filter does not change.
        $overview = [];
        if ($fragment) {
            $this->catchUp->run($scope);
        } else {
            $overview = $this->screen->overview($scope);
        }

        $total = $this->subscriptions->count($scope, $filter);
        $items = $this->subscriptions->list($scope, $filter);

        $data = [
            'subscriptions' => $items,
            'rows' => $this->screen->rows($scope, $items),
            'summary' => $this->screen->summary($scope, $filter, $total),
            'filter' => $filter,
            'total' => $total,
            'page_count' => max(1, (int) ceil($total / $filter->perPage)),
            'categories' => $this->categories->all($scope),
            'tags' => $this->tags->all($scope),
            'statuses' => SubscriptionStatus::cases(),
            // Household / Mine, offered only where it could change anything: a
            // member who can only see their own rows would get the same list
            // from both.
            'offers_scope' => $scope->hasHousehold() && !$scope->restrictsReadsToOwner(),
            'members' => $scope->hasHousehold()
                ? $this->memberships->findMembersOfHousehold((int) $scope->householdId)
                : [],
            'all_currencies' => Currency::all(),
            'saved_views' => $this->savedViews->forScope($scope),
            // What "save this view" would store: the filter as the list itself
            // would write it, rather than whatever is in the address bar.
            'current_query' => $filter->toQueryString(['page' => null]),
            'density' => $this->user($request)->densityPreference()->value,
        ] + $overview;

        // htmx asks for just the table when filtering, sorting or paging.
        return $this->render(
            $request,
            $response,
            $fragment ? 'subscriptions/_list.twig' : 'subscriptions/index.twig',
            $data,
        );
    }

    /**
     * The list as a CSV file: the same filter, the same scoped query, every
     * matched row rather than one page.
     */
    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $filter = SubscriptionFilter::fromQueryParams($request->getQueryParams())->withIncludeInactive();

        $response->getBody()->write($this->export->csv($scope, $filter));

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->export->filename($this->clock->today()) . '"',
            )
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The same rows as `export()`, as JSON.
     */
    public function exportJson(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $filter = SubscriptionFilter::fromQueryParams($request->getQueryParams())->withIncludeInactive();

        $response->getBody()->write($this->export->json($scope, $filter));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->export->filename($this->clock->today(), 'json') . '"',
            )
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The list's density toggle. The same preference the profile page sets,
     * written on its own, and back to the list with the filter the member was
     * looking at — re-parsed through the value object, like a saved view, so
     * the query field is never a redirect to anywhere else.
     */
    public function density(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $this->preferences->updateDensity(
            $this->user($request)->id,
            is_scalar($body['density'] ?? null) ? (string) $body['density'] : null,
        );

        parse_str(is_scalar($body['query'] ?? null) ? (string) $body['query'] : '', $query);
        $filter = SubscriptionFilter::fromQueryParams($query);
        $queryString = $filter->toQueryString(['page' => null]);

        return $this->redirectAfterWrite(
            $request,
            $response,
            '/subscriptions' . ($queryString !== '' ? '?' . $queryString : ''),
        );
    }

    /**
     * "≈ £12.34 at today's rate", beneath the form's price. Asked for by htmx
     * as the price or currency changes, so the conversion is the server's.
     */
    public function conversionNote(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        return $this->render($request, $response, 'subscriptions/_conversion_note.twig', [
            'note' => $this->screen->conversionNote(
                is_scalar($query['price'] ?? null) ? (string) $query['price'] : '',
                is_scalar($query['currency'] ?? null) ? (string) $query['currency'] : '',
            ),
        ]);
    }

    /**
     * Apply one action to a selection of subscriptions.
     *
     * Guarded by the BulkEdit permission on the route, and by the scoping layer
     * on every individual write — a selection containing ids the caller cannot
     * write simply changes fewer rows, and says so.
     */
    public function bulk(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $changed = $this->bulkActions->apply($this->scope($request), $body);
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);

            return $this->redirectAfterWrite($request, $response, '/subscriptions');
        }

        $this->flash('success', 'flash.bulk_updated', ['count' => $changed]);

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'subscriptions/form.twig', $this->formData($request, [
            'subscription_type' => SubscriptionType::Recurring->value,
            'billing_cycle' => BillingCycle::Monthly->value,
            'is_active' => '1',
            // Today is a safe guess for when a subscription began — it is
            // usually being added because it has just been taken out. The next
            // payment is not guessable in the same way: the cycle has not been
            // chosen yet, so any date offered would be arbitrary, and one that
            // is silently accepted is worse than one the user has to enter.
            'start_date' => $this->clock->today()->format('Y-m-d'),
            'reminder_mode' => SubscriptionFormService::REMINDER_DEFAULT,
            'split_mode' => 'none',
        ]));
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        try {
            $body['logo_path'] = $this->logos->store($this->uploadedLogo($request)) ?? '';
            $this->form->create($scope, $body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'subscriptions/form.twig',
                $this->formData($request, $body, $exception->errors()),
            );
        }

        $this->flash('success', 'flash.subscription_added');

        // Back to the list, the same place a save from the edit form lands.
        // The new row is visible there, which is the confirmation the user is
        // actually after; dropping them back into a form they have just
        // finished filling in reads as though something went wrong.
        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function editForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $subscription = $this->subscriptions->find($scope, (int) $id);

        // Readable is not editable. A split participant in ISOLATED mode and a
        // Contributor both see rows they may not change, and handing either of
        // them a filled-in form that cannot be saved is a worse answer than not
        // opening it — the read-only view of somebody else's subscription is
        // its cost page.
        if ($subscription === null || !$scope->mayWriteRow($subscription->householdId, $subscription->ownerUserId)) {
            // Out of scope and non-existent are deliberately the same answer:
            // a 403 here would confirm that somebody else's row exists.
            throw $this->notFound($request);
        }

        // The scenario planner's Change price sends the member here with the
        // new terms in the query; they fill the form and are saved, if at all,
        // through it.
        $values = $this->form->prefill([
            'id' => $subscription->id,
            'name' => $subscription->name,
            'price' => $subscription->price->toDecimalString(),
            'currency' => $subscription->price->currency,
            'subscription_type' => $subscription->type->value,
            'billing_cycle' => $subscription->billingCycle?->value,
            'cycle_days' => $subscription->cycleDays,
            'cycle_interval' => $subscription->cycleInterval,
            // On when the anchor is the 31st and the next charge sits on a
            // shorter month's end — the one case the switch is what keeps the
            // anchor. A row dated the 31st anchors there without it, so it is
            // not shown a switch nobody turned on.
            'anchor_last_day' => $subscription->billingCycle === BillingCycle::Monthly
                && $subscription->anchorDay === BillingCycle::LAST_DAY_ANCHOR
                && $subscription->nextPaymentDate !== null
                && (int) $subscription->nextPaymentDate->format('j') < BillingCycle::LAST_DAY_ANCHOR ? '1' : '0',
            'next_payment_date' => $subscription->nextPaymentDate?->format('Y-m-d'),
            'start_date' => $subscription->startDate?->format('Y-m-d'),
            'is_trial' => $subscription->isTrial ? '1' : '0',
            'trial_end_date' => $subscription->trialEndDate?->format('Y-m-d'),
            'converts_to_price' => $subscription->convertsToPrice?->toDecimalString(),
            'converts_to_billing_cycle' => $subscription->convertsToBillingCycle?->value,
            'converts_to_cycle_days' => $subscription->convertsToCycleDays,
            'converts_to_cycle_interval' => $subscription->convertsToCycleInterval,
            'notice_period_amount' => $subscription->noticePeriod->amount,
            'notice_period_unit' => $subscription->noticePeriod->unit,
            'contract_ends_on' => $subscription->contractEndsOn?->format('Y-m-d'),
            'payments_end_on' => $subscription->paymentsEndOn?->format('Y-m-d'),
            'amount_varies' => $subscription->amountVaries ? '1' : '0',
            'category_id' => $subscription->categoryId,
            'payment_method_id' => $subscription->paymentMethodId,
            'owner_user_id' => $subscription->ownerUserId,
            'payer_user_id' => $subscription->payerUserId,
            'visibility' => $subscription->visibility->value,
            'plan' => $subscription->plan,
            'notes' => $subscription->notes,
            'is_active' => $subscription->isActive ? '1' : '0',
            'logo_path' => $subscription->logoPath,
            'website_url' => $subscription->websiteUrl,
            'tags' => implode(', ', array_map(static fn ($tag): string => $tag->name, $subscription->tags)),
        ] + $this->priceHistory->offerFormValues($scope, $subscription->id), $request->getQueryParams());

        return $this->render($request, $response, 'subscriptions/form.twig', $this->formData($request, $values
            // The three reminder states — use my defaults, never, these days —
            // as the chips draw them, and the split as its controls do.
            + $this->form->reminderValues($subscription->reminderDays)
            + $this->form->splitValues(
                $subscription,
                $this->splits->participants($scope, $subscription->id),
            ), [], $subscription->id)
            + ['schedule' => $this->form->schedulePrefill($request->getQueryParams())]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        $body = $this->body($request);

        // The logo is only ever the file uploaded with this request. A
        // `logo_path` in the posted form is not one, whatever it names — a
        // third-party URL, or a co-member's stored logo, which deleting this
        // row would then delete (K1).
        unset($body['logo_path']);

        try {
            $uploaded = $this->logos->store($this->uploadedLogo($request));
            if ($uploaded !== null) {
                $body['logo_path'] = $uploaded;
            }

            $this->form->update($scope, (int) $id, $body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'subscriptions/form.twig',
                $this->formData($request, $body, $exception->errors(), (int) $id),
            );
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.subscription_saved');

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);

        try {
            $subscription = $this->subscriptions->find($scope, (int) $id);
            $this->subscriptions->delete($scope, (int) $id);
            $this->logos->delete($subscription?->logoPath);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', 'flash.subscription_deleted');

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function toggle(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $scope = $this->scope($request);
        $subscription = $this->subscriptions->find($scope, (int) $id);

        if ($subscription === null) {
            throw $this->notFound($request);
        }

        try {
            $this->subscriptions->setActive($scope, $subscription->id, !$subscription->isActive);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        } catch (ValidationException $exception) {
            // Resuming a cancelled subscription (the way back is un-cancel),
            // or a fixed term with no billing date left (move its end first).
            $error = $exception->errors()['payments_end_on'] ?? null;
            $this->flash('error', $error?->key ?? 'error.subscription.cancelled_resume');

            return $this->redirectAfterWrite($request, $response, '/subscriptions');
        }

        $this->flash('success', $subscription->isActive ? 'flash.subscription_paused' : 'flash.subscription_resumed');

        return $this->redirectAfterWrite($request, $response, '/subscriptions');
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->changeCancellation($request, $response, (int) $id, true);
    }

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

        try {
            $cancel ? $this->subscriptions->cancel($scope, $id) : $this->subscriptions->uncancel($scope, $id);
        } catch (ScopeViolationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', $cancel ? 'flash.subscription_cancelled' : 'flash.subscription_uncancelled');

        return $this->redirectAfterWrite($request, $response, $this->cancellationReturn($request, $id));
    }

    /**
     * Where a cancel sends the member back to.
     *
     * The dashboard's Cancel trial uses this same action, and should leave the
     * member on the dashboard, and the edit page's Cancel subscription should
     * leave them on the edit page. Only the paths that carry the control are
     * honoured — a named allowlist rather than any local path, so the field
     * can never become a redirect to somewhere unexpected.
     */
    private function cancellationReturn(ServerRequestInterface $request, int $id): string
    {
        $body = $this->body($request);
        $target = is_scalar($body['return_to'] ?? null) ? (string) $body['return_to'] : '';

        // The scenario planner's Cancel comes back to the planner with its
        // scenario. The path is fixed and the query rebuilt from the planner's
        // own parameters, so nothing but the planner can be reached this way.
        if (parse_url($target, PHP_URL_PATH) === '/forecast/scenario') {
            parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
            $query = array_intersect_key($query, array_flip(['cancel', 'change', 'mine']));

            return '/forecast/scenario' . ($query === [] ? '' : '?' . http_build_query($query));
        }

        return match ($target) {
            '/' => '/',
            '/subscriptions/' . $id . '/edit' => $target,
            default => '/subscriptions',
        };
    }

    private function uploadedLogo(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $logo = $files['logo'] ?? null;

        return $logo instanceof UploadedFileInterface ? $logo : null;
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, \App\Service\ValidationError> $errors
     * @return array<string, mixed>
     */
    private function formData(
        ServerRequestInterface $request,
        array $values,
        array $errors = [],
        ?int $id = null,
    ): array {
        $scope = $this->scope($request);
        $subscription = $id !== null ? $this->subscriptions->find($scope, $id) : null;
        $members = $scope->hasHousehold()
            ? $this->memberships->findMembersOfHousehold((int) $scope->householdId)
            : [];

        // The split controls are drawn only for somebody who could save them,
        // and only where there is anybody to split with.
        $offersSplit = count($members) > 1 && $this->form->mayEditSplit($scope, $subscription);

        // A row split before this form carried the split: "only me" cannot be
        // chosen while it stays split, and here it cannot be un-split either.
        $isSplit = $subscription?->splitMode->isSplit() ?? false;

        // Every ISO currency, and the row's own when it is one since withdrawn,
        // so an edit never silently changes it to whatever is first.
        $currency = is_scalar($values['currency'] ?? null) ? (string) $values['currency'] : '';
        $currencies = Currency::all();
        if ($currency !== '' && !in_array($currency, $currencies, true)) {
            $currencies[] = $currency;
            sort($currencies);
        }

        $chosenDays = [];
        foreach ((array) ($values['reminder_day'] ?? []) as $day) {
            if (is_scalar($day) && ctype_digit((string) $day)) {
                $chosenDays[] = (int) $day;
            }
        }

        return [
            'values' => $values,
            'errors' => $errors,
            'subscription_id' => $id,
            'subscription' => $subscription,
            'is_split' => $isSplit,
            'offers_split' => $offersSplit,
            'categories' => $this->categories->all($scope),
            'payment_methods' => $this->paymentMethods->all($scope),
            'members' => $members,
            'currencies' => $currencies,
            'base_currency_note' => $this->screen->conversionNote(
                is_scalar($values['price'] ?? null) ? (string) $values['price'] : '',
                $currency,
            ),
            'cycles' => BillingCycle::cases(),
            'types' => SubscriptionType::cases(),
            'notice_units' => NoticePeriod::units(),
            'reminder_choices' => $this->form->reminderChoices($chosenDays),
            // The household's existing tags, so the form can offer them rather
            // than make the user remember how they spelled one last time. The
            // field still accepts anything typed into it: `TagRepository::
            // resolveOrCreate()` matches an existing name or creates a new tag,
            // so choosing and inventing are the same request.
            'tags' => $this->tags->all($scope),
            // The edit page's own sections: the price history with its
            // schedule-a-change form, and the attachments.
            'trend' => $subscription !== null ? $this->priceHistory->trendFor($scope, $subscription->id) : [],
            // What the schedule form's "then costs" starts as: the ordinary
            // price an offer would go back to.
            'offer_then_default' => $subscription !== null
                ? $this->priceHistory->ordinaryPrice($scope, $subscription->id)?->toDecimalString()
                : null,
            'attachments' => $subscription !== null
                ? $this->attachments->forSubscription($scope, $subscription->id)
                : [],
        ];
    }
}
