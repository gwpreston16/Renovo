<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Entity\User;
use App\I18n\Translator;
use App\Application\Middleware\AuthenticationMiddleware;
use App\Repository\MembershipRepository;
use App\Security\SessionInterface;
use App\Notification\NotifierRegistry;
use App\Notification\NotifierException;
use App\Service\Notification\NotificationSettingsService;
use App\Service\SetupService;
use App\Service\SetupWizardService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * First-run wizard: three steps and a page to end on.
 *
 *  1. `/setup` — the owner account. The only step before anybody is signed
 *     in, reachable only while the instance has no users; SetupGuardMiddleware
 *     closes it afterwards.
 *  2. `/setup/household` — its name, the base currency, and under More options
 *     data visibility and the rate provider; invitations to send at the end.
 *  3. `/setup/notifications` — email, budget alerts, lead times, and any other
 *     channel, each with a test.
 *
 * Each step is its own request, so Back and Continue are a link and a form
 * and the wizard works without script. Steps two and three run signed in, as
 * the new administrator, and close once the wizard is finished.
 */
final class SetupController extends Controller
{
    /** The invitations step two collected, held until setup finishes. */
    private const SESSION_INVITES = 'setup_invites';

    /** What the done page summarises, set by finishing and read once. */
    private const SESSION_SUMMARY = 'setup_summary';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly SetupService $setup,
        private readonly SetupWizardService $wizard,
        private readonly MembershipRepository $memberships,
        private readonly NotificationSettingsService $notifications,
        private readonly NotifierRegistry $notifiers,
        private readonly string $smtpHost = '',
        private readonly string $mailFrom = '',
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function showForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'setup/wizard.twig', $this->formData([]));
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $user = $this->setup->complete($body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'setup/wizard.twig',
                $this->formData($body, $exception->errors()),
            );
        }

        $this->session->regenerate();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $user->id);

        $memberships = $this->memberships->findAllForUser($user->id);
        if ($memberships !== []) {
            $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $memberships[0]->householdId);
        }

        return $this->redirect($response, '/setup/household');
    }

    /**
     * Step two: the household.
     */
    public function showHousehold(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $scope = $this->scope($request);

        if (!$this->wizard->isOpenTo($user) || $scope->householdId === null) {
            return $this->redirect($response, '/');
        }

        return $this->render($request, $response, 'setup/household.twig', $this->householdData(
            $scope->householdId,
            ['invites' => implode(', ', $this->pendingInvites())],
        ));
    }

    public function saveHousehold(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $scope = $this->scope($request);

        if (!$this->wizard->isOpenTo($user) || $scope->householdId === null) {
            return $this->redirect($response, '/');
        }

        $body = $this->body($request);

        try {
            $invites = $this->wizard->saveHousehold($user, $scope->householdId, $body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'setup/household.twig',
                $this->householdData($scope->householdId, $body, $exception->errors()),
            );
        }

        $this->session->set(self::SESSION_INVITES, $invites);

        return $this->redirect($response, '/setup/notifications');
    }

    /**
     * Step three: reminders, and a test message to prove they arrive.
     *
     * Sending a real message is the part of this that matters. A form that
     * accepts a Gotify token and says "saved" has verified nothing; the first
     * time the operator learns the token was wrong would otherwise be the
     * renewal they missed.
     */
    public function showNotifications(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->user($request);

        if (!$this->wizard->isOpenTo($user)) {
            return $this->redirect($response, '/');
        }

        return $this->render($request, $response, 'setup/notifications.twig', $this->notificationStepData($user));
    }

    public function addNotificationChannel(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->user($request);

        if (!$this->wizard->isOpenTo($user)) {
            return $this->redirect($response, '/');
        }

        $body = $this->body($request);

        $strings = [];
        foreach ($body as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $strings[$key] = (string) $value;
            }
        }

        try {
            $this->notifications->createChannel($user, $strings);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'setup/notifications.twig',
                $this->notificationStepData($user, $exception->errors(), $body),
            );
        }

        $this->flash('success', 'flash.setup_channel_added');

        return $this->redirectAfterWrite($request, $response, '/setup/notifications#channels');
    }

    /**
     * "Send test email": the step's switches are saved first, so the message
     * goes through the email channel the owner has just said they want.
     */
    public function sendTestEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);

        if (!$this->wizard->isOpenTo($user)) {
            return $this->redirect($response, '/');
        }

        $body = $this->body($request);

        try {
            $this->wizard->saveReminders($user, $body);
        } catch (ValidationException $exception) {
            return $this->render(
                $request,
                $response->withStatus(422),
                'setup/notifications.twig',
                $this->notificationStepData($user, $exception->errors(), $body),
            );
        }

        try {
            if ($this->wizard->sendTestEmail($user)) {
                $this->flash('success', 'flash.setup_test_email_sent', ['address' => $user->email]);
            } else {
                $this->flash('error', 'flash.setup_test_email_off');
            }
        } catch (NotifierException $exception) {
            // The relay's own words: it knows what went wrong, this does not.
            $this->flash('error', 'flash.raw', ['message' => $exception->getMessage()]);
        }

        return $this->redirectAfterWrite($request, $response, '/setup/notifications#mail-relay');
    }

    public function finishNotifications(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $this->user($request);

        if (!$this->wizard->isOpenTo($user)) {
            return $this->redirect($response, '/');
        }

        $body = $this->body($request);

        // Only a post from the step's own form names its settings. Anything
        // else — a finish that skips the step — leaves the defaults alone.
        if (($body['reminders'] ?? '') === '1') {
            try {
                $this->wizard->saveReminders($user, $body);
            } catch (ValidationException $exception) {
                return $this->render(
                    $request,
                    $response->withStatus(422),
                    'setup/notifications.twig',
                    $this->notificationStepData($user, $exception->errors(), $body),
                );
            }
        }

        $summary = $this->wizard->finish($user, $this->scope($request), $this->pendingInvites());

        $this->session->remove(self::SESSION_INVITES);
        $this->session->set(self::SESSION_SUMMARY, $summary);

        return $this->redirectAfterWrite($request, $response, '/setup/done');
    }

    /**
     * The page setup ends on. Shown once: it is a summary of what just
     * happened, and a reload afterwards goes where the summary points.
     */
    public function done(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $summary = $this->session->get(self::SESSION_SUMMARY);
        $this->session->remove(self::SESSION_SUMMARY);

        if (!is_array($summary)) {
            return $this->redirect($response, '/');
        }

        return $this->render($request, $response, 'setup/done.twig', ['summary' => $summary]);
    }

    /**
     * @return list<string>
     */
    private function pendingInvites(): array
    {
        $invites = $this->session->get(self::SESSION_INVITES);

        return is_array($invites) ? array_values(array_filter($invites, 'is_string')) : [];
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array<string, \App\Service\ValidationError|string> $errors
     * @return array<string, mixed>
     */
    private function householdData(int $householdId, array $submitted = [], array $errors = []): array
    {
        return $this->wizard->householdStep($householdId) + [
            'values' => $submitted,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, \App\Service\ValidationError|string> $errors
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    private function notificationStepData(User $user, array $errors = [], array $submitted = []): array
    {
        return $this->wizard->remindersStep($user) + [
            'notifiers' => array_values(array_filter(
                $this->notifiers->all(),
                static fn (\App\Notification\Notifier $notifier): bool => $notifier->key() !== 'email',
            )),
            // Shown rather than described. The step's job is to make the mail
            // relay visible, and "if one is configured" leaves the operator to
            // work out for themselves whether it is.
            'smtp_host' => $this->smtpHost,
            'mail_from' => $this->mailFrom,
            'user_email' => $user->email,
            'errors' => $errors,
            'submitted' => $submitted,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, \App\Service\ValidationError> $errors
     * @return array<string, mixed>
     */
    private function formData(array $values, array $errors = []): array
    {
        return [
            'values' => $values,
            'errors' => $errors,
        ];
    }
}
