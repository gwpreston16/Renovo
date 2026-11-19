<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\I18n\Translator;
use App\Service\CalendarFeedService;
use App\Service\InstanceSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The `.ics` feed.
 *
 * Served as a file download rather than inline: a browser that follows the link
 * by accident gets a file its calendar application knows what to do with, and
 * `nosniff` keeps it from being interpreted as anything else.
 *
 * A poll that sends back the ETag of the feed as it stands gets a 304 and no
 * body (K259). The feed is still built every time; only the sending is
 * saved.
 */
final class CalendarApiController extends ApiController
{
    public function __construct(
        Translator $translator,
        private readonly CalendarFeedService $calendar,
        private readonly InstanceSettingsService $settings,
    ) {
        parent::__construct($translator);
    }

    public function feed(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $feed = $this->calendar->build($this->scope($request), $this->settings->instanceName());

        $response = $response
            ->withHeader('ETag', $feed->etag)
            // A feed is fetched on a timer and is never worth a shared cache.
            // `no-cache`, not `no-store`: the client may keep its copy, which
            // is what a 304 refers to, but must ask again on every poll.
            ->withHeader('Cache-Control', 'private, no-cache');

        if ($feed->matches($request->getHeaderLine('If-None-Match'))) {
            return $response->withStatus(304);
        }

        $response->getBody()->write($feed->body);

        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="renovo.ics"')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
