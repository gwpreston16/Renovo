<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\BillingCycle;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\CatchUpService;
use App\Service\ForecastScreenService;
use App\Service\InstanceSettingsService;
use App\Service\ScenarioService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The twelve-month forecast.
 *
 * A "just mine" toggle rather than two pages: the household view and the
 * per-member view answer the same question at different scopes, and the
 * service already takes the member as a parameter. Everything on the page is
 * `ForecastScreenService`'s.
 */
final class ForecastController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly ForecastScreenService $screen,
        private readonly CatchUpService $catchUp,
        private readonly InstanceSettingsService $settings,
        private readonly ScenarioService $scenarios,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $this->catchUp->run($scope);

        $mineOnly = ($request->getQueryParams()['mine'] ?? '') === '1';

        return $this->render($request, $response, 'forecast/index.twig', $this->screen->overview(
            $scope,
            $mineOnly ? $scope->userId : null,
        ) + [
            'mine_only' => $mineOnly,
            'base_currency' => $this->settings->baseCurrency(),
        ]);
    }

    /**
     * The scenario planner, a tab of the Forecast screen.
     *
     * The scenario is the query string, so this is a GET: bookmarkable, and
     * it works without script. The planner's own form also sends a choice per
     * row; a plain submit of it is redirected to the short form, so the
     * address bar always holds the link worth keeping. An htmx request for
     * the results panel gets only that; any other, a filter's, the page. Both
     * are told the same short address to show.
     */
    public function scenario(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $this->catchUp->run($scope);

        $query = $request->getQueryParams();
        $mineOnly = ($query['mine'] ?? '') === '1';
        $plan = $this->scenarios->plan($scope, $query, $mineOnly ? $scope->userId : null);

        $address = '/forecast/scenario' . ($plan['query'] !== '' ? '?' . $plan['query'] : '');
        $data = $plan + ['mine_only' => $mineOnly, 'base_currency' => $this->settings->baseCurrency()];

        if ($this->isHtmx($request)) {
            // A choice replaces only the results; a filter replaces the whole
            // planner, which htmx picks out of the full page.
            $template = $request->getHeaderLine('HX-Target') === 'scenario-results'
                ? 'forecast/_scenario_results.twig'
                : 'forecast/scenario.twig';

            return $this->render($request, $response, $template, $data)
                ->withHeader('HX-Replace-Url', $this->withFilters($address, $query));
        }

        if (isset($query['choice']) && !$plan['scenario']->hasErrors()) {
            return $this->redirect($response, $this->withFilters($address, $query));
        }

        return $this->render($request, $response, 'forecast/scenario.twig', $data);
    }

    /**
     * One row's change-price controls (K181).
     *
     * The planner draws a price, cycle and days field only for a row whose
     * choice is Change; choosing Change on a row asks for that row's here.
     * The row is read through the scoped list like the page's, so another
     * household's id and one the member cannot see are the same 404 as any
     * other missing row. Nothing is written.
     */
    public function rowControls(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $id = (int) $id;

        $row = $this->scenarios->rowFor($scope, $id, $request->getQueryParams());
        if ($row === null) {
            throw $this->notFound($request);
        }

        return $this->render($request, $response, 'forecast/_scenario_change.twig', [
            'row' => $row,
            'id' => $id,
            'cycles' => BillingCycle::cases(),
        ]);
    }

    /**
     * The no-script "Work it out": the whole form, posted so that it is not
     * held to the length of an address (K211), answered with the short
     * address that holds just the rows that change something. The planner's
     * own address stays the scenario, so a bookmark keeps it.
     */
    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = $this->body($request);
        $query = $this->scenarios->queryFromForm($form);

        $address = '/forecast/scenario' . ($query === [] ? '' : '?' . http_build_query($query));

        return $this->redirect($response, $this->withFilters($address, $form))->withStatus(303);
    }

    /**
     * The subscriptions list's "Plan a scenario": the ticked rows, set to
     * Cancel. A POST because the bulk bar is a form with a CSRF token, which
     * has no place in an address; it writes nothing and answers with the
     * planner's own address, where anything out of scope is dropped.
     */
    public function selection(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ids = $this->body($request)['ids'] ?? [];

        $cancel = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            if (is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0) {
                $cancel[] = 'cancel[]=' . (int) $id;
            }
        }

        return $this->redirect($response, '/forecast/scenario' . ($cancel === [] ? '' : '?' . implode('&', $cancel)))
            ->withStatus(303);
    }

    /**
     * The planner's address with the row filters carried, so a reload keeps
     * the rows the member had narrowed to.
     *
     * @param array<mixed> $query
     */
    private function withFilters(string $address, array $query): string
    {
        $filters = [];
        foreach (['category', 'tag', 'owner'] as $key) {
            $value = $query[$key] ?? null;
            if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                $filters[] = $key . '=' . (int) $value;
            }
        }

        if ($filters === []) {
            return $address;
        }

        return $address . (str_contains($address, '?') ? '&' : '?') . implode('&', $filters);
    }
}
