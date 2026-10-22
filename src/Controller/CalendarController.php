<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Api\ApiPath;
use App\Application\Middleware\FeedAuthenticationMiddleware;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\ApiTokenService;
use App\Service\CalendarService;
use App\Service\CatchUpService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The month view of what is coming, and the calendar feed's link.
 *
 * The same "just mine" toggle as the forecast, and the same catch-up first: a
 * calendar showing a payment date that passed last week would be the most
 * obviously wrong page in the application.
 *
 * Choosing a day is a link: htmx swaps the month in place, and without script
 * `?day=` is an ordinary page load showing the same panel.
 */
final class CalendarController extends Controller
{
    private const FLASH_FEED_TOKEN = 'new_feed_token';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly CalendarService $calendar,
        private readonly CatchUpService $catchUp,
        private readonly ApiTokenService $tokens,
        private readonly string $appUrl,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $user = $this->user($request);

        $query = $request->getQueryParams();
        $mineOnly = ($query['mine'] ?? '') === '1';
        $day = $this->calendar->resolveDay(is_string($query['day'] ?? null) ? $query['day'] : null);

        // A day on its own names its month; a month given as well wins.
        $month = $this->calendar->resolveMonth(
            is_string($query['month'] ?? null) ? $query['month'] : $day?->format('Y-m'),
            $scope,
        );
        if ($month === null) {
            throw $this->notFound($request);
        }

        $this->catchUp->run($scope);

        // The new link is shown once, on the page load that follows creating
        // it — never in an htmx fragment, which does not carry the card.
        $issued = null;
        if (!$this->isHtmx($request)) {
            $flashed = $this->session->get(self::FLASH_FEED_TOKEN);
            $this->session->remove(self::FLASH_FEED_TOKEN);
            $issued = is_string($flashed) ? $flashed : null;
        }

        $feedUrl = rtrim($this->appUrl, '/') . ApiPath::PREFIX . '/calendar.ics';

        return $this->renderMaybeFragment(
            $request,
            $response,
            'calendar/index.twig',
            'calendar/_month.twig',
            [
                'calendar' => $this->calendar->month(
                    $scope,
                    $month,
                    $user->weekStartPreference(),
                    $mineOnly ? $scope->userId : null,
                    $day,
                ),
                'mine_only' => $mineOnly,
                'feed' => [
                    'token' => $this->tokens->feedToken($user, $scope->householdId),
                    'url' => $issued === null
                        ? null
                        : $feedUrl . '?' . http_build_query([FeedAuthenticationMiddleware::QUERY_PARAMETER => $issued]),
                ],
            ],
        );
    }

    /**
     * Create a new feed link, retiring the old one.
     *
     * No permission is named on this route, for the API-token routes' reason:
     * it acts only on the signed-in member's own read-only token, which can
     * never read more than they can. A Viewer may have a feed.
     */
    public function replaceFeed(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $this->tokens->replaceFeedToken($this->user($request), $this->scope($request)->householdId);

        $this->session->set(self::FLASH_FEED_TOKEN, $token);
        $this->flash('success', 'flash.feed_link_created');

        return $this->redirectAfterWrite($request, $response, '/calendar#calendar-feed');
    }
}
