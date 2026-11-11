<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Domain\BudgetPeriod;
use App\Security\SessionInterface;
use App\Service\BudgetScreenService;
use App\Service\BudgetService;
use App\Service\CategoryService;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Budgets. Thin, like every controller here: the projection, the validation and
 * the ownership rules all live in BudgetService, which the API will call
 * unchanged when it arrives.
 */
final class BudgetController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly BudgetService $budgets,
        private readonly BudgetScreenService $screen,
        private readonly CategoryService $categories,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render(
            $request,
            $response,
            'budgets/index.twig',
            $this->screen->screen($this->scope($request)),
        );
    }

    public function createForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'budgets/form.twig', $this->formData($request, [
            'period' => BudgetPeriod::Monthly->value,
            'follows_pay_day' => '0',
            'warn_threshold_percent' => BudgetService::DEFAULT_WARN_THRESHOLD,
        ]));
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->budgets->create($this->scope($request), $body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'budgets/form.twig',
                $this->formData($request, $body, $exception->errors()),
            );
        }

        $this->flash('success', 'flash.budget_created');

        return $this->redirectAfterWrite($request, $response, '/budgets');
    }

    public function editForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $budget = $this->budgets->find($scope, (int) $id);
        // A budget the viewer may read but not write — a Contributor looking
        // at the household's — has no form to offer: the save would be
        // refused by the repository, so the form is not pretended either.
        if ($budget === null || !$scope->mayWriteRow($budget->householdId, $budget->ownerUserId)) {
            throw $this->notFound($request);
        }

        return $this->render($request, $response, 'budgets/form.twig', $this->formData($request, [
            'id' => $budget->id,
            'name' => $budget->name,
            'category_id' => $budget->categoryId,
            'period' => $budget->period->value,
            'follows_pay_day' => $budget->followsPayDay && $budget->period === BudgetPeriod::Monthly ? '1' : '0',
            'amount' => $budget->amount->toDecimalString(),
            'currency' => $budget->amount->currency,
            'warn_threshold_percent' => $budget->warnThresholdPercent ?? BudgetService::DEFAULT_WARN_THRESHOLD,
            'subject_user_id' => $budget->subjectUserId ?? BudgetService::SUBJECT_HOUSEHOLD,
        ], [], $budget->id));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $this->budgets->update($this->scope($request), (int) $id, $body);
        } catch (ValidationException $exception) {
            $body['currency'] ??= $this->budgets->find($this->scope($request), (int) $id)?->amount->currency;

            return $this->render(
                $request,
                $response->withStatus(422),
                'budgets/form.twig',
                $this->formData($request, $body, $exception->errors(), (int) $id),
            );
        }

        $this->flash('success', 'flash.budget_saved');

        return $this->redirectAfterWrite($request, $response, '/budgets');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $this->budgets->delete($this->scope($request), (int) $id);
        $this->flash('success', 'flash.budget_deleted');

        return $this->redirectAfterWrite($request, $response, '/budgets');
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

        // Whose spending an existing budget measures, when that is not a
        // choice this viewer is offered: shown, and not sent, so the save
        // leaves it as it is.
        $budget = $id === null ? null : $this->budgets->find($scope, $id);
        $lockedSubject = $budget !== null && !$this->budgets->offersSubject($scope, $budget->subjectUserId)
            ? ['household' => $budget->isHousehold(), 'name' => $budget->subjectName]
            : null;

        return [
            'values' => $values,
            'errors' => $errors,
            'budget_id' => $id,
            'categories' => $this->categories->all($scope),
            // Shortest-but-commonest first: a month, then the pay-day rhythms,
            // then the year.
            'periods' => [
                BudgetPeriod::Monthly,
                BudgetPeriod::Fortnight,
                BudgetPeriod::FourWeeks,
                BudgetPeriod::Annual,
            ],
            // The limit's currency: the budget's own on an edit, else the base.
            'currency' => is_string($values['currency'] ?? null) && $values['currency'] !== ''
                ? $values['currency']
                : $this->settings->baseCurrency(),
            'subjects' => $this->budgets->subjectOptions($scope),
            'locked_subject' => $lockedSubject,
            // Whose pay day an existing budget follows; null for a new one,
            // which measures the viewer unless they choose otherwise.
            'pay_day_member' => $budget?->payDayUserId(),
        ];
    }
}
