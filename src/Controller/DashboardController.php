<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\DashboardCard;
use App\Domain\DashboardView;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\DashboardLayoutService;
use App\Service\DashboardService;
use App\Service\HouseholdDashboardService;
use App\Service\InstanceSettingsService;
use App\Service\SavingsService;
use App\Service\SpendTrendService;
use App\Service\UserPreferencesService;
use App\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Views\Twig;

final class DashboardController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly DashboardService $dashboard,
        private readonly HouseholdDashboardService $household,
        private readonly DashboardLayoutService $layout,
        private readonly SpendTrendService $trend,
        private readonly UserPreferencesService $preferences,
        private readonly Clock $clock,
        private readonly InstanceSettingsService $settings,
        private readonly SavingsService $savings,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $user = $this->user($request);

        // A chip on the subscriptions table asks for the table, and nothing
        // else: recomputing the whole view would re-run the catch-up and walk
        // the forecast to answer a question about eight rows. Keyed on the
        // chip's own parameter as well as the htmx header, so no other htmx
        // request to `/` can be mistaken for it.
        $show = $request->getQueryParams()['show'] ?? null;
        if ($this->isHtmx($request) && is_string($show)) {
            return $this->render($request, $response, 'dashboard/_recent_table.twig', [
                'table' => $this->dashboard->table($scope, $show),
            ]);
        }

        // Spend over time's two views are links, and htmx asks for the card
        // alone, for the same reason as the table's chips: switching lines
        // should not recompute the rest of the Household view.
        $trendBy = $request->getQueryParams()['trend'] ?? null;
        $trendBy = is_string($trendBy) ? $trendBy : SpendTrendService::BY_CATEGORY;
        if ($this->isHtmx($request) && isset($request->getQueryParams()['trend']) && $scope->hasHousehold()) {
            return $this->render($request, $response, 'dashboard/cards/household/spend_trend.twig', [
                'trend' => $this->trend->trend($scope, $trendBy),
            ]);
        }

        // No "Open on" redirect here. That preference is about where a *session*
        // begins, so it is applied once, when the browser signs in — see
        // SignInService::landingFor(). Applied on this route it behaved as a
        // permanent redirect, and since the navigation's Dashboard item points
        // at `/`, it made the dashboard unreachable for anyone whose preference
        // was some other screen.
        $view = $user->dashboardViewPreference();

        // Customising draws every card of the view, hidden ones included, so
        // a hidden card can be found and shown again. Hidden cards are drawn
        // as a placeholder only, which is why the table below still asks
        // whether its own card is visible rather than merely present.
        // Not offered on a demonstration: every write is refused there, so
        // each drag would snap back and each button end on an error page.
        $editing = ($request->getQueryParams()['layout'] ?? null) === 'edit' && !$this->settings->isDemoMode();
        $layout = $this->layout->forUser($user->id, $view);
        $cards = DashboardLayoutService::visibleOf($layout);

        $data = [];
        if ($scope->hasHousehold()) {
            $data = $view === DashboardView::Household
                ? $this->household->household($scope, $trendBy)
                : $this->dashboard->overview($scope);

            // The table is opt-in, so its query runs only for somebody who
            // has turned it on.
            if (in_array(DashboardCard::Recent, $cards, true)) {
                $data['table'] = $this->dashboard->table($scope, is_string($show) ? $show : 'active');
            }
            // And the savings, for the same reason: they read the ledger and
            // walk every cancelled row.
            if (in_array(DashboardCard::Savings, $cards, true)) {
                $data['savings'] = $this->savings->forScope($scope);
            }
        }

        return $this->render($request, $response, 'dashboard/index.twig', $data + [
            'dashboard_view' => $view,
            'dashboard_views' => DashboardView::cases(),
            'dashboard_cards' => $cards,
            'dashboard_layout' => $layout,
            'editing' => $editing,
            'first_name' => $user->firstName(),
            'today' => $this->clock->today(),
        ]);
    }

    /**
     * The Overview / Household toggle.
     *
     * Remembered per account, so the dashboard opens on the same view next
     * time. A personal preference like the theme: it changes nothing anybody
     * else sees, so it asks for no permission beyond being signed in.
     */
    public function updateView(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $this->preferences->updateDashboardView(
            $this->user($request)->id,
            is_scalar($body['view'] ?? null) ? (string) $body['view'] : null,
        );

        // The toggle is drawn while customising too, and choosing the other
        // view there is choosing to arrange it next, so it stays in the mode.
        $editing = ($body['layout'] ?? null) === 'edit';

        return $this->redirectAfterWrite($request, $response, $editing ? '/?layout=edit' : '/');
    }

    /**
     * One change to the layout of the view being customised.
     *
     * Either the whole order, as `order[]` — what a drag sends — or one card
     * and what to do with it: `up`, `down`, `show` or `hide`, the buttons that
     * work with no script and from the keyboard. `reset` on its own puts the
     * view back to its default layout. Like the view toggle it is a
     * personal preference and acts on the session's own account only, so it
     * asks for no permission.
     *
     * The script's saves are htmx requests and get an empty 204: it has
     * already moved the card on the page, and a redirect would reload it.
     */
    public function updateLayout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $userId = $this->user($request)->id;

        $view = DashboardView::tryFrom(is_scalar($body['view'] ?? null) ? (string) $body['view'] : '');
        if ($view === null) {
            throw new HttpBadRequestException($request, 'Unknown dashboard view.');
        }

        $anchor = '';
        if (($body['action'] ?? null) === 'reset') {
            $this->layout->reset($userId, $view);
        } elseif (is_array($body['order'] ?? null)) {
            $keys = array_values(array_filter(array_map(
                static fn (mixed $key): string => is_scalar($key) ? (string) $key : '',
                $body['order'],
            ), static fn (string $key): bool => $key !== ''));

            $this->layout->reorder($userId, $view, $keys);
        } else {
            $card = DashboardCard::tryFrom(is_scalar($body['card'] ?? null) ? (string) $body['card'] : '');
            if ($card === null || $card->view() !== $view) {
                throw new HttpBadRequestException($request, 'Unknown dashboard card.');
            }

            match ($body['action'] ?? null) {
                'up' => $this->layout->move($userId, $view, $card, -1),
                'down' => $this->layout->move($userId, $view, $card, 1),
                'show' => $this->layout->setVisible($userId, $view, $card, true),
                'hide' => $this->layout->setVisible($userId, $view, $card, false),
                default => throw new HttpBadRequestException($request, 'Unknown layout action.'),
            };

            $anchor = '#card-' . $card->value;
        }

        if ($this->isHtmx($request)) {
            return $response->withStatus(204);
        }

        // Without script the page reloads, so say that the change was kept —
        // the status line a screen reader would otherwise hear is empty.
        $this->flash('success', $anchor === '' ? 'flash.dashboard_layout_reset' : 'flash.dashboard_layout_saved');

        return $this->redirect($response, '/?layout=edit' . $anchor);
    }
}
