<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Application\AppVersion;
use App\Domain\Entity\User;
use App\Domain\SemanticVersion;
use App\Http\BlockedTargetException;
use App\Http\OutboundContext;
use App\Http\OutboundMode;
use App\Http\OutboundRefusal;
use App\Repository\UserRepository;
use App\Service\InstanceSettingsService;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether a newer Renovo has been released, asked of GitHub at most once a
 * day, and only when an instance administrator has turned it on.
 *
 * **Nothing is fetched while checks are off.** Off in settings, off through
 * `UPDATE_CHECK=false`, or off because `UPDATE_CHECK_REPOSITORY` is not an
 * `owner/name` — the scheduler step, the dashboard's lazy check, the command
 * and Check now all ask `isEnabled()` before anything else.
 *
 * **Only three facts are kept** from GitHub's answer: the tag, as a version;
 * the release page; and when it was published. The release notes are markup
 * from a third party and are never stored or drawn — the page is one link
 * away. `/releases/latest` already leaves out drafts and pre-releases.
 *
 * **A failure keeps the last good answer** and backs off for an hour before
 * the next automatic attempt, as the exchange-rate refresh does, so GitHub
 * being down never turns into a request on every dashboard load. Check now
 * is held to one request a minute.
 *
 * The request goes through the shared HTTP client with tighter limits than
 * the instance's own (see the container): a short timeout, because the lazy
 * check runs while an administrator waits for their dashboard, and a small
 * response cap, because a release is a few kilobytes of JSON.
 */
final class UpdateCheckService
{
    /** The container key of the narrowed HTTP client this service is given. */
    public const HTTP_CLIENT = 'http.update_check';

    /** A check is due a day after the last good one, less an hour of slack for the scheduler's drift. */
    public const STALE_SECONDS = 82800;
    /** After a failure, the next automatic attempt waits this long. */
    public const RETRY_SECONDS = 3600;
    /** Check now is refused within this long of the last attempt. */
    public const CHECK_NOW_INTERVAL_SECONDS = 60;

    /** The width of `users.dismissed_update_version`. */
    private const MAX_VERSION_LENGTH = 32;

    private const RELEASE_PAGE_PREFIX = 'https://github.com/';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly InstanceSettingsService $settings,
        private readonly UserRepository $users,
        private readonly AppVersion $version,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly UpdateCheckConfig $config,
        private readonly ?OutboundContext $outbound = null,
        private readonly ?OutboundMode $outboundMode = null,
    ) {
    }

    /** Why the last attempt failed, so a refusal by the instance can be told from GitHub being down. */
    private ?Throwable $lastFailure = null;

    /** The user who pressed Check now, for the audit entry of a refusal. */
    private ?int $actorUserId = null;

    /**
     * True when checks are on in settings and the environment allows them.
     */
    public function isEnabled(): bool
    {
        return $this->config->unavailableReason() === null && $this->settings->updateCheckEnabled();
    }

    /**
     * Why the environment rules checks out, whatever the setting says.
     */
    public function unavailableReason(): ?UpdateUnavailable
    {
        return $this->config->unavailableReason();
    }

    /**
     * The scheduler's step and the dashboard's lazy check: a check if one is
     * due and the back-off allows it, with any failure logged and swallowed.
     *
     * @return bool Whether a check was attempted.
     */
    public function refreshIfDue(): bool
    {
        // An instance that sends nothing makes no attempt of its own: the
        // dashboard would only collect a refusal an hour. Check now still
        // tries, and says why it cannot.
        if ($this->outboundMode === OutboundMode::Off) {
            return false;
        }

        if (!$this->mayCheck() || !$this->isDue() || !$this->backOffHasLifted()) {
            return false;
        }

        $this->checkQuietly();

        return true;
    }

    /**
     * A check now, whenever the last one was: `updates:check`.
     */
    public function check(): CheckNowResult
    {
        if (!$this->mayCheck()) {
            return CheckNowResult::Off;
        }

        return $this->checkQuietly() ? CheckNowResult::Checked : $this->failedResult();
    }

    /**
     * The Instance status card's Check now: a check, held to one a minute.
     */
    public function checkNow(?int $actorUserId = null): CheckNowResult
    {
        $this->actorUserId = $actorUserId;
        if (!$this->mayCheck()) {
            return CheckNowResult::Off;
        }

        $lastAttempt = $this->settings->updateLastAttemptAt();
        if (
            $lastAttempt !== null
            && $lastAttempt->getTimestamp() + self::CHECK_NOW_INTERVAL_SECONDS > $this->clock->now()->getTimestamp()
        ) {
            return CheckNowResult::TooSoon;
        }

        return $this->checkQuietly() ? CheckNowResult::Checked : $this->failedResult();
    }

    private function failedResult(): CheckNowResult
    {
        return match ($this->lastFailure === null ? null : OutboundRefusal::reasonOf($this->lastFailure)) {
            BlockedTargetException::OUTBOUND_OFF => CheckNowResult::OutboundOff,
            BlockedTargetException::OUTBOUND_NOT_ALLOWLISTED => CheckNowResult::OutboundNotAllowed,
            default => CheckNowResult::Failed,
        };
    }

    public function status(): UpdateStatus
    {
        $release = $this->settings->latestRelease();
        $checkedAt = $this->settings->updateCheckedAt();
        $lastAttempt = $this->settings->updateLastAttemptAt();

        $state = match (true) {
            !$this->isEnabled() => UpdateState::Off,
            $this->lastAttemptFailed() => UpdateState::Failed,
            $checkedAt === null => UpdateState::NeverChecked,
            $release['version'] === '' => UpdateState::NoReleases,
            $this->isNewer($release['version']) => UpdateState::Available,
            default => UpdateState::UpToDate,
        };

        return new UpdateStatus(
            $state,
            $this->version->current(),
            $release['version'] === '' ? null : $release['version'],
            $this->releasePage($release['url']),
            $release['published_at'],
            $checkedAt,
            $lastAttempt,
            $this->config->unavailableReason(),
        );
    }

    /**
     * The banner for this reader, or null when they get none: only an
     * instance administrator, only with checks and the banner on, only for a
     * release newer than this one and newer than the last they dismissed.
     */
    public function bannerFor(User $user): ?UpdateBanner
    {
        if (!$user->isInstanceAdmin || !$this->isEnabled() || !$this->settings->updateBannerEnabled()) {
            return null;
        }

        $release = $this->settings->latestRelease();
        if (!$this->isNewer($release['version'])) {
            return null;
        }

        $latest = SemanticVersion::parse($release['version']);
        $dismissed = SemanticVersion::parse($user->dismissedUpdateVersion ?? '');
        if ($latest === null || ($dismissed !== null && !$latest->isNewerThan($dismissed))) {
            return null;
        }

        return new UpdateBanner($latest->toString(), $this->version->current(), $this->releasePage($release['url']));
    }

    /**
     * The dashboard's banner for this reader, after the lazy check: an
     * instance administrator loading the dashboard is what keeps an instance
     * with no working scheduler learning of releases, at most once a day.
     * Nobody else's page load asks GitHub anything.
     */
    public function forDashboard(User $user): ?UpdateBanner
    {
        if (!$user->isInstanceAdmin) {
            return null;
        }

        $this->refreshIfDue();

        return $this->bannerFor($user);
    }

    /**
     * Hide the banner from this administrator until a release newer than the
     * one it announces appears.
     *
     * `$shown` is the version the banner displayed, posted back by its form,
     * so a release found after the page was drawn still gets its own banner.
     * It is untrusted: one that is not a version, or is newer than the stored
     * latest, is ignored and the stored latest is recorded.
     */
    public function dismiss(User $user, ?string $shown = null): void
    {
        $latest = $this->settings->latestRelease()['version'];
        if ($latest === '') {
            return;
        }

        $record = $latest;
        $posted = SemanticVersion::parse($shown ?? '');
        $stored = SemanticVersion::parse($latest);
        if ($posted !== null && $stored !== null && !$posted->isNewerThan($stored)) {
            $record = $posted->toString();
        }

        $this->users->setDismissedUpdateVersion($user->id, $record);
    }

    private function mayCheck(): bool
    {
        if (!$this->settings->updateCheckEnabled()) {
            return false;
        }

        $reason = $this->config->unavailableReason();
        if ($reason === UpdateUnavailable::InvalidRepository) {
            // Said every time a check would otherwise have run, so an operator
            // reading the log learns why the switch they turned on does nothing.
            $this->logger->warning('Update check disabled: UPDATE_CHECK_REPOSITORY is not an owner/name.', [
                'repository' => $this->config->repository,
            ]);
        }

        return $reason === null;
    }

    private function isDue(): bool
    {
        $checkedAt = $this->settings->updateCheckedAt();

        return $checkedAt === null
            || $checkedAt->getTimestamp() + self::STALE_SECONDS <= $this->clock->now()->getTimestamp();
    }

    private function backOffHasLifted(): bool
    {
        $lastAttempt = $this->settings->updateLastAttemptAt();

        return !$this->lastAttemptFailed()
            || $lastAttempt === null
            || $lastAttempt->getTimestamp() + self::RETRY_SECONDS <= $this->clock->now()->getTimestamp();
    }

    /**
     * A successful check records its time at or after the attempt's, so an
     * attempt later than the last success is one that failed.
     */
    private function lastAttemptFailed(): bool
    {
        $lastAttempt = $this->settings->updateLastAttemptAt();
        if ($lastAttempt === null) {
            return false;
        }

        $checkedAt = $this->settings->updateCheckedAt();

        return $checkedAt === null || $lastAttempt > $checkedAt;
    }

    private function checkQuietly(): bool
    {
        $this->lastFailure = null;

        try {
            $this->fetch();

            return true;
        } catch (UpdateCheckException $exception) {
            $this->lastFailure = $exception;
            $this->logger->warning('Update check failed.', ['reason' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * @throws UpdateCheckException
     */
    private function fetch(): void
    {
        $this->settings->markUpdateCheckAttempted($this->clock->now());

        $request = $this->requests->createRequest('GET', $this->config->latestReleaseUrl())
            ->withHeader('Accept', 'application/vnd.github+json')
            ->withHeader('X-GitHub-Api-Version', '2022-11-28')
            // GitHub refuses a request without one. Set here, per request, so
            // it names this release rather than the instance-wide agent.
            ->withHeader('User-Agent', 'Renovo/' . $this->version->current());

        try {
            $send = fn (): ResponseInterface => $this->http->sendRequest($request);
            $response = $this->outbound === null
                ? $send()
                : $this->outbound->within(OutboundContext::UPDATES, $this->actorUserId, $send);
        } catch (ClientExceptionInterface $exception) {
            throw new UpdateCheckException($exception->getMessage(), 0, $exception);
        }

        $status = $response->getStatusCode();
        if ($status === 404) {
            // No release published yet: an answer, not a failure.
            $this->settings->recordLatestRelease('', '', null, $this->clock->now());

            return;
        }

        if ($status < 200 || $status >= 300) {
            throw new UpdateCheckException(sprintf('GitHub answered %d.', $status));
        }

        try {
            $payload = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UpdateCheckException('The response was not JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($payload)) {
            throw new UpdateCheckException('The response was not a JSON object.');
        }

        $tag = is_string($payload['tag_name'] ?? null) ? $payload['tag_name'] : '';
        $version = SemanticVersion::parse($tag);
        if ($version === null || strlen($version->toString()) > self::MAX_VERSION_LENGTH) {
            throw new UpdateCheckException(
                sprintf('The latest release is tagged "%s", which is not a version.', mb_substr($tag, 0, 64)),
            );
        }

        $url = is_string($payload['html_url'] ?? null) ? $payload['html_url'] : '';

        $this->settings->recordLatestRelease(
            $version->toString(),
            mb_substr($url, 0, 500),
            $this->publishedAt($payload['published_at'] ?? null),
            $this->clock->now(),
        );
    }

    private function publishedAt(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    private function isNewer(string $latest): bool
    {
        $latestVersion = SemanticVersion::parse($latest);
        $current = SemanticVersion::parse($this->version->current());

        return $latestVersion !== null && $current !== null && $latestVersion->isNewerThan($current);
    }

    /**
     * The stored release page, or null unless it is on GitHub over https.
     */
    private function releasePage(string $url): ?string
    {
        if (!str_starts_with($url, self::RELEASE_PAGE_PREFIX) || preg_match('/[\s"\'<>\\\\]/', $url) === 1) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
    }
}
