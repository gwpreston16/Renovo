<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Domain\AlertType;
use App\Domain\DigestMode;
use App\Domain\Entity\NotificationPreferences;
use App\Notification\NotifierException;
use App\Notification\NotifierRegistry;
use App\Repository\NotificationLogRepository;
use App\Security\SessionInterface;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationSettingsService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * A user's own notification settings.
 *
 * No permission beyond being signed in, for the same reason changing your own
 * theme needs none: everything on this page belongs to the account making the
 * request, and every service call is keyed to that account's id rather than to
 * anything in the URL. A Viewer may configure their own reminders — being
 * unable to change household data is not a reason to be unable to hear about
 * it.
 */
final class NotificationController extends Controller
{
    private const PAGE = '/settings/notifications';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly NotificationSettingsService $settings,
        private readonly NotifierRegistry $notifiers,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationLogRepository $log,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'notifications/index.twig', $this->pageData($request));
    }

    public function createChannel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $body = $this->body($request);

        try {
            $this->settings->createChannel($user, $this->strings($body));
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'notifications/index.twig',
                $this->pageData($request, $exception->errors(), $body),
            );
        }

        $this->flash('success', 'flash.channel_added');

        return $this->redirectAfterWrite($request, $response, self::PAGE);
    }

    public function updateChannel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $user = $this->user($request);
        $body = $this->body($request);

        try {
            $this->settings->updateChannel($user, (int) $id, $this->strings($body));
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'notifications/index.twig',
                $this->pageData($request, $exception->errors(), $body),
            );
        }

        $this->flash('success', 'flash.channel_updated');

        return $this->redirectAfterWrite($request, $response, self::PAGE);
    }

    /**
     * The switch beside a channel. Posted on change with script, and with a
     * button without it; either way the channel's settings are untouched.
     */
    public function setActive(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $body = $this->body($request);
        $active = ($body['is_active'] ?? '0') === '1';

        try {
            $this->settings->setChannelActive($this->user($request), (int) $id, $active);
        } catch (ValidationException) {
            throw $this->notFound($request);
        }

        $this->flash('success', $active ? 'flash.channel_turned_on' : 'flash.channel_turned_off');

        return $this->redirectAfterWrite($request, $response, self::PAGE . '#channels');
    }

    public function deleteChannel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $this->settings->deleteChannel($this->user($request), (int) $id);
        $this->flash('success', 'flash.channel_removed');

        return $this->redirectAfterWrite($request, $response, self::PAGE);
    }

    public function test(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $user = $this->user($request);
        $channel = $this->settings->channel($user->id, (int) $id);

        if ($channel === null) {
            throw $this->notFound($request);
        }

        try {
            $this->dispatcher->sendTest($user, $channel);
            $this->flash('success', 'flash.test_message_sent', ['label' => $channel->label]);
        } catch (NotifierException $exception) {
            // Shown rather than logged and swallowed: the whole point of a test
            // button is to put the failure in front of the person who can fix
            // it, while they are looking at the settings that caused it.
            // A notifier's own words about why a delivery failed: the
            // channel knows what went wrong and this application does not, so
            // the sentence is passed through rather than reinvented as a key.
            $this->flash('error', 'flash.raw', ['message' => $exception->getMessage()]);
        }

        return $this->redirectAfterWrite($request, $response, $this->returnTo($request));
    }

    public function updatePreferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $body = $this->body($request);

        $routes = $body['routes'] ?? null;
        $routes = is_array($routes) ? $routes : [];

        try {
            $this->settings->savePreferences($user->id, $body);
        } catch (ValidationException $exception) {
            // The routing grid and the lead times are one form, so a typo in
            // the lead times must not silently revert the checkboxes the user
            // also changed. Nothing is saved, and what they submitted is what
            // the form shows back.
            return $this->render(
                $request,
                $response->withStatus(422),
                'notifications/index.twig',
                $this->pageData($request, $exception->errors(), $body, $this->submittedRoutes($routes)),
            );
        }

        $this->settings->saveRoutes($user->id, $routes);

        $this->flash('success', 'flash.notification_preferences_saved');

        return $this->redirectAfterWrite($request, $response, self::PAGE);
    }

    /**
     * Turn the submitted routing grid back into the shape the template reads,
     * so a rejected form redisplays the user's choices rather than the stored
     * ones.
     *
     * @param array<int|string, mixed> $routes
     * @return array<int, list<string>>
     */
    private function submittedRoutes(array $routes): array
    {
        $shaped = [];
        foreach ($routes as $channelId => $types) {
            if (!is_array($types)) {
                continue;
            }

            foreach ($types as $type) {
                if (is_scalar($type)) {
                    $shaped[(int) $channelId][] = (string) $type;
                }
            }
        }

        return $shaped;
    }

    /**
     * @param array<string, \App\Service\ValidationError> $errors
     * @param array<string, mixed> $submitted
     * @param array<int, list<string>>|null $routes
     * @return array<string, mixed>
     */
    private function pageData(
        ServerRequestInterface $request,
        array $errors = [],
        array $submitted = [],
        ?array $routes = null,
    ): array {
        $user = $this->user($request);
        $preferences = $this->settings->preferences($user->id);

        // The chips offered, plus any lead time already stored that is not
        // one of them, so that saving the page keeps it.
        $leadChoices = array_values(array_unique([
            ...NotificationPreferences::OFFERED_LEAD_DAYS,
            ...$preferences->leadDaysOutsideOffered(),
        ]));
        sort($leadChoices);

        return [
            'channels' => $this->settings->channels($user->id),
            'notifiers' => $this->notifiers->all(),
            'preferences' => $preferences,
            'lead_choices' => $leadChoices,
            'lead_types' => array_values(array_filter(
                AlertType::all(),
                static fn (AlertType $type): bool => $type->usesLeadTimes(),
            )),
            'routes' => $routes ?? $this->settings->routes($user->id),
            'alert_types' => AlertType::all(),
            'digest_modes' => DigestMode::cases(),
            'recent' => $this->log->findRecentForUser($user->id, 10),
            'errors' => $errors,
            'submitted' => $submitted,
        ];
    }

    /**
     * Where the test button should send the user back to. The wizard uses the
     * same endpoint and must not be thrown out of its own flow by it.
     */
    private function returnTo(ServerRequestInterface $request): string
    {
        $body = $this->body($request);
        $target = is_scalar($body['return_to'] ?? null) ? (string) $body['return_to'] : '';

        // The wizard's reminders step is the one other page with a test
        // button, and the only other place this sends anybody.
        return $target === '/setup/notifications' || $target === '/setup/notifications#channels'
            ? $target
            : self::PAGE . '#channels';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function strings(array $body): array
    {
        $strings = [];
        foreach ($body as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $strings[$key] = (string) $value;
            }
        }

        return $strings;
    }
}
