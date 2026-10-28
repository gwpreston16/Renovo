<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\AppVersion;
use App\Domain\Entity\User;
use App\Http\BlockedTargetException;
use App\Http\HttpClientException;
use App\Http\OutboundMode;
use App\Repository\InstanceSettingsRepository;
use App\Repository\UserRepository;
use App\Service\InstanceSettingsService;
use App\Service\Update\CheckNowResult;
use App\Service\Update\UpdateCheckConfig;
use App\Service\Update\UpdateCheckService;
use App\Service\Update\UpdateState;
use App\Service\Update\UpdateUnavailable;
use App\Support\FrozenClock;
use App\Tests\Support\FakeHttpClient;
use App\Tests\Support\ForbiddenHttpClient;
use App\Tests\Support\RecordingLogger;
use DateTimeImmutable;
use Psr\Http\Client\ClientInterface;
use Slim\Psr7\Factory\RequestFactory;
use App\Tests\Support\TestKeys;

/**
 * The update check: what it asks GitHub, what it keeps, when it backs off, and
 * above all that it asks nothing at all while checks are off.
 */
final class UpdateCheckServiceTest extends DatabaseTestCase
{
    private InstanceSettingsService $settings;
    private UserRepository $users;
    private FrozenClock $clock;
    private string $versionFile;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        $this->users = new UserRepository($this->db);
        $this->clock = FrozenClock::at('2026-10-23 09:00:00');
        $this->versionFile = (string) tempnam(sys_get_temp_dir(), 'renovo-version-');
        file_put_contents($this->versionFile, '1.7.0');

        $this->logger = new RecordingLogger();
        $this->settings->setUpdateCheckEnabled(true);
    }

    protected function tearDown(): void
    {
        @unlink($this->versionFile);
        parent::tearDown();
    }

    public function testAsksGitHubThroughTheClientWithTheHeadersItNeeds(): void
    {
        $http = $this->release('v1.8.0');

        self::assertSame(CheckNowResult::Checked, $this->service($http)->check());

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame(
            'https://api.github.com/repos/gwpreston16/Renovo/releases/latest',
            (string) $request->getUri(),
        );
        self::assertSame('application/vnd.github+json', $request->getHeaderLine('Accept'));
        self::assertSame('Renovo/1.7.0', $request->getHeaderLine('User-Agent'));
    }

    public function testKeepsOnlyTheVersionThePageAndTheDate(): void
    {
        $this->service($this->release('v1.8.0', ['body' => '<script>alert(1)</script>']))->check();

        $release = $this->settings->latestRelease();
        self::assertSame('1.8.0', $release['version']);
        self::assertSame('https://github.com/gwpreston16/Renovo/releases/tag/v1.8.0', $release['url']);
        self::assertSame('2026-10-20 12:00:00', $release['published_at']?->format('Y-m-d H:i:s'));

        $stored = implode(' ', (new InstanceSettingsRepository($this->db))->all());
        self::assertStringNotContainsString('script', $stored, 'The release notes are never stored.');
    }

    public function testANewerReleaseIsAvailable(): void
    {
        $service = $this->service($this->release('v1.10.0'));
        $service->check();

        $status = $service->status();
        self::assertSame(UpdateState::Available, $status->state);
        self::assertSame('1.10.0', $status->latestVersion);
        self::assertSame('https://github.com/gwpreston16/Renovo/releases/tag/v1.10.0', $status->releaseUrl);
    }

    public function testTheSameReleaseIsUpToDate(): void
    {
        $service = $this->service($this->release('v1.7.0'));
        $service->check();

        self::assertSame(UpdateState::UpToDate, $service->status()->state);
    }

    public function testAnOlderReleaseIsUpToDate(): void
    {
        $service = $this->service($this->release('v1.6.0'));
        $service->check();

        self::assertSame(UpdateState::UpToDate, $service->status()->state);
    }

    public function testNoReleaseYetIsAnAnswerNotAFailure(): void
    {
        $service = $this->service(FakeHttpClient::returningJson(['message' => 'Not Found'], 404));

        self::assertSame(CheckNowResult::Checked, $service->check());
        self::assertSame(UpdateState::NoReleases, $service->status()->state);
        self::assertNull($service->bannerFor($this->admin()));
    }

    public function testATagThatIsNotAVersionIsAFailureThatKeepsTheLastGoodResult(): void
    {
        $this->service($this->release('v1.8.0'))->check();

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-24 10:00:00'));
        $service = $this->service($this->release('nightly'));

        self::assertSame(CheckNowResult::Failed, $service->check());

        $status = $service->status();
        self::assertSame(UpdateState::Failed, $status->state);
        self::assertSame('1.8.0', $status->latestVersion, 'The last good result is kept.');
        self::assertSame('2026-10-23 09:00:00', $status->checkedAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-24 10:00:00', $status->lastAttemptAt?->format('Y-m-d H:i:s'));
        self::assertNotNull($service->bannerFor($this->admin()), 'The banner still uses the last good result.');
        self::assertSame('Update check failed.', $this->logger->records[0][1] ?? null);
    }

    public function testAnOversizedResponseFailsCleanly(): void
    {
        $request = (new RequestFactory())->createRequest('GET', 'https://api.github.com/');
        $service = $this->service(FakeHttpClient::throwing(HttpClientException::responseTooLarge($request, 65536)));

        self::assertSame(CheckNowResult::Failed, $service->check());
        self::assertSame(UpdateState::Failed, $service->status()->state);
    }

    public function testASlowResponseFailsCleanly(): void
    {
        $request = (new RequestFactory())->createRequest('GET', 'https://api.github.com/');
        $service = $this->service(FakeHttpClient::throwing(
            HttpClientException::transport($request, 'Operation timed out after 5000 milliseconds'),
        ));

        self::assertSame(CheckNowResult::Failed, $service->check());
    }

    public function testAnErrorStatusOrABodyThatIsNotJsonFails(): void
    {
        $forbidden = FakeHttpClient::returning('rate limited', 403);
        self::assertSame(CheckNowResult::Failed, $this->service($forbidden)->check());
        self::assertSame(CheckNowResult::Failed, $this->service(FakeHttpClient::returning('<html>'))->check());
        self::assertSame(CheckNowResult::Failed, $this->service(FakeHttpClient::returning('"a string"'))->check());
    }

    public function testRefreshesOnlyWhenADayHasPassed(): void
    {
        $http = $this->release('v1.8.0');
        $service = $this->service($http);

        self::assertTrue($service->refreshIfDue(), 'Never checked, so due.');
        self::assertFalse($service->refreshIfDue(), 'Checked a moment ago.');

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-24 07:59:00'));
        self::assertFalse($service->refreshIfDue(), 'Under 23 hours.');

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-24 08:00:00'));
        self::assertTrue($service->refreshIfDue());
        self::assertCount(2, $http->requests);
    }

    public function testAfterAFailureTheNextAttemptWaitsForTheBackOff(): void
    {
        $http = FakeHttpClient::failing();
        $service = $this->service($http);

        self::assertTrue($service->refreshIfDue());
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-23 09:59:00'));
        self::assertFalse($service->refreshIfDue(), 'Inside the hour.');

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-23 10:00:00'));
        self::assertTrue($service->refreshIfDue());
        self::assertCount(2, $http->requests);
    }

    public function testCheckNowIsHeldToOneAMinute(): void
    {
        $http = $this->release('v1.8.0');
        $service = $this->service($http);

        self::assertSame(CheckNowResult::Checked, $service->checkNow());

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-23 09:00:59'));
        self::assertSame(CheckNowResult::TooSoon, $service->checkNow());

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-23 09:01:00'));
        self::assertSame(CheckNowResult::Checked, $service->checkNow());
        self::assertCount(2, $http->requests);
    }

    public function testWithChecksOffNothingIsRequested(): void
    {
        $this->settings->setUpdateCheckEnabled(false);
        $http = new ForbiddenHttpClient();
        $service = $this->service($http);

        self::assertFalse($service->refreshIfDue());
        self::assertSame(CheckNowResult::Off, $service->check());
        self::assertSame(CheckNowResult::Off, $service->checkNow());
        self::assertSame(UpdateState::Off, $service->status()->state);
        self::assertSame(0, $http->calls);
    }

    public function testTheEnvironmentOverridesTheSetting(): void
    {
        $http = new ForbiddenHttpClient();
        $service = $this->service($http, new UpdateCheckConfig(false));

        self::assertFalse($service->isEnabled());
        self::assertFalse($service->refreshIfDue());
        self::assertSame(CheckNowResult::Off, $service->check());
        self::assertSame(CheckNowResult::Off, $service->checkNow());
        self::assertSame(UpdateUnavailable::DisabledByEnvironment, $service->status()->unavailable);
        self::assertSame(0, $http->calls);
    }

    public function testAnInvalidRepositoryDisablesTheCheckWithAWarning(): void
    {
        $http = new ForbiddenHttpClient();
        $service = $this->service($http, new UpdateCheckConfig(true, 'not a repository'));

        self::assertSame(CheckNowResult::Off, $service->check());
        self::assertSame(UpdateUnavailable::InvalidRepository, $service->unavailableReason());
        self::assertSame(0, $http->calls);
        self::assertSame(
            ['warning', 'Update check disabled: UPDATE_CHECK_REPOSITORY is not an owner/name.'],
            $this->logger->records[0] ?? null,
        );
    }

    public function testAForkChecksItsOwnRepository(): void
    {
        $http = $this->release('v1.8.0');
        $this->service($http, new UpdateCheckConfig(true, 'someone/renovo-fork'))->check();

        self::assertSame(
            'https://api.github.com/repos/someone/renovo-fork/releases/latest',
            (string) $http->requests[0]->getUri(),
        );
    }

    public function testConfigReadsTheEnvironment(): void
    {
        // The reader looks in `$_ENV` and then the process environment, so a
        // runner that exports UPDATE_CHECK would otherwise decide the result:
        // set both for the keys, and put both back as they were.
        $keys = ['UPDATE_CHECK', 'UPDATE_CHECK_REPOSITORY'];
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = [
                'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
                'process' => getenv($key, true),
            ];
        }

        $set = static function (array $values) use ($keys): void {
            foreach ($keys as $key) {
                if (isset($values[$key])) {
                    $_ENV[$key] = $values[$key];
                    putenv($key . '=' . $values[$key]);
                } else {
                    unset($_ENV[$key]);
                    putenv($key);
                }
            }
        };

        try {
            $set(['UPDATE_CHECK' => 'false', 'UPDATE_CHECK_REPOSITORY' => 'someone/fork']);
            $config = UpdateCheckConfig::fromEnvironment();
            self::assertFalse($config->allowedByEnvironment);
            self::assertSame('someone/fork', $config->repository);

            $set([]);
            $config = UpdateCheckConfig::fromEnvironment();
            self::assertTrue($config->allowedByEnvironment);
            self::assertSame(UpdateCheckConfig::DEFAULT_REPOSITORY, $config->repository);
        } finally {
            foreach ($before as $key => $value) {
                if ($value['env'] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value['env'];
                }

                if (is_string($value['process'])) {
                    putenv($key . '=' . $value['process']);
                } else {
                    putenv($key);
                }
            }
        }
    }

    public function testTheBannerIsForInstanceAdminsWithANewerRelease(): void
    {
        $service = $this->service($this->release('v1.8.0'));
        $service->check();

        $banner = $service->bannerFor($this->admin());
        self::assertNotNull($banner);
        self::assertSame('1.8.0', $banner->latestVersion);
        self::assertSame('1.7.0', $banner->currentVersion);

        self::assertNull($service->bannerFor($this->member()), 'Not for someone who is not an instance admin.');
    }

    public function testNoBannerWhenTheBannerIsOffOrTheReleaseIsNotNewer(): void
    {
        $service = $this->service($this->release('v1.8.0'));
        $service->check();
        $admin = $this->admin();
        $this->settings->setUpdateBannerEnabled(false);

        self::assertNull($service->bannerFor($admin));

        $this->settings->setUpdateBannerEnabled(true);
        $current = $this->service($this->release('v1.7.0'));
        $current->check();
        self::assertNull($current->bannerFor($admin));
    }

    public function testNoBannerWithChecksOffEvenWithANewerReleaseStored(): void
    {
        $service = $this->service($this->release('v1.8.0'));
        $service->check();
        $this->settings->setUpdateCheckEnabled(false);

        self::assertNull($service->bannerFor($this->admin()));
    }

    public function testDismissingHidesTheBannerFromThatAdminUntilANewerRelease(): void
    {
        $service = $this->service($this->release('v1.8.0'));
        $service->check();
        $first = $this->admin('first@example.test');
        $second = $this->admin('second@example.test');

        $service->dismiss($first);
        $first = $this->reload($first);

        self::assertNull($service->bannerFor($first));
        self::assertNotNull($service->bannerFor($second), 'Another admin still sees it.');

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-25 09:00:00'));
        $newer = $this->service($this->release('v1.9.0'));
        $newer->check();

        self::assertSame('1.9.0', $newer->bannerFor($first)?->latestVersion, 'A newer release brings it back.');
    }

    public function testDismissingWithNothingStoredDoesNothing(): void
    {
        $service = $this->service(new ForbiddenHttpClient());
        $admin = $this->admin();

        $service->dismiss($admin);

        self::assertNull($this->reload($admin)->dismissedUpdateVersion);
    }

    public function testUnreadablePublishedAtDoesNotBreakTheCheck(): void
    {
        // A date PHP can't parse is dropped, not thrown: the release is still
        // news, and the admin's dashboard and `reminders:run` don't fail on it.
        $service = $this->service($this->release('v1.8.0', ['published_at' => 'the day after tomorrow-ish']));

        self::assertSame(CheckNowResult::Checked, $service->check());

        $status = $service->status();
        self::assertSame('1.8.0', $status->latestVersion);
        self::assertNull($status->publishedAt);
        self::assertNotNull($service->bannerFor($this->admin()));
    }

    public function testOverlongVersionIsRefused(): void
    {
        $service = $this->service($this->release('v1.8.0'));
        $service->check();

        // 38 characters would overflow the dismissed-version column.
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-24 10:00:00'));
        $long = $this->service($this->release('v1.9.0-alpha.beta.gamma.delta.epsilon'));

        self::assertSame(CheckNowResult::Failed, $long->check());
        self::assertSame('1.8.0', $long->status()->latestVersion, 'Not stored; the last good result is kept.');
    }

    public function testOnlyGithubReleasePagesAreLinked(): void
    {
        $good = $this->service($this->release('v1.8.0'));
        $good->check();
        self::assertSame(
            'https://github.com/gwpreston16/Renovo/releases/tag/v1.8.0',
            $good->status()->releaseUrl,
        );

        foreach (
            [
                'https://github.com.evil.example/gwpreston16/Renovo/releases/tag/v1.8.0',
                'http://github.com/gwpreston16/Renovo/releases/tag/v1.8.0',
                'https://github.com@evil.example/x y',
            ] as $url
        ) {
            $service = $this->service($this->release('v1.8.0', ['html_url' => $url]));
            $service->check();

            self::assertNull($service->status()->releaseUrl, $url);
        }
    }

    public function testAReleasePageOffGitHubIsNotLinked(): void
    {
        $service = $this->service($this->release('v1.8.0', ['html_url' => 'https://evil.example/releases/v1.8.0']));
        $service->check();
        $banner = $service->bannerFor($this->admin());

        self::assertNull($service->status()->releaseUrl);
        self::assertNotNull($banner, 'The banner is still shown, without a link.');
        self::assertNull($banner->releaseUrl);

        $quoted = $this->service($this->release('v1.8.0', ['html_url' => 'https://github.com/x" onclick="y']));
        $quoted->check();
        self::assertNull($quoted->status()->releaseUrl);
    }

    public function testCheckNowSaysWhenTheInstanceRefusedToSendAndNotThatGitHubIsDown(): void
    {
        $cases = [
            [BlockedTargetException::OUTBOUND_OFF, CheckNowResult::OutboundOff],
            [BlockedTargetException::OUTBOUND_NOT_ALLOWLISTED, CheckNowResult::OutboundNotAllowed],
        ];

        foreach ($cases as [$reason, $expected]) {
            $http = FakeHttpClient::throwing(BlockedTargetException::outbound($reason, 'api.github.com'));

            self::assertSame($expected, $this->service($http)->check());
            // A later, ordinary failure is a failure again: the reason is not remembered.
            self::assertSame(CheckNowResult::Failed, $this->service(FakeHttpClient::failing())->check());
        }
    }

    public function testTheLazyAndScheduledCheckMakeNoAttemptWhileOutboundRequestsAreOff(): void
    {
        $http = $this->release('v1.8.0');
        $service = new UpdateCheckService(
            $http,
            new RequestFactory(),
            $this->settings,
            $this->users,
            new AppVersion($this->versionFile),
            $this->clock,
            $this->logger,
            new UpdateCheckConfig(),
            null,
            OutboundMode::Off,
        );

        self::assertFalse($service->refreshIfDue());
        self::assertSame([], $http->requests);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function release(string $tag, array $extra = []): FakeHttpClient
    {
        return FakeHttpClient::returningJson($extra + [
            'tag_name' => $tag,
            'html_url' => 'https://github.com/gwpreston16/Renovo/releases/tag/' . $tag,
            'published_at' => '2026-10-20T12:00:00Z',
            'draft' => false,
            'prerelease' => false,
        ]);
    }

    private function service(ClientInterface $http, ?UpdateCheckConfig $config = null): UpdateCheckService
    {
        return new UpdateCheckService(
            $http,
            new RequestFactory(),
            $this->settings,
            $this->users,
            new AppVersion($this->versionFile),
            $this->clock,
            $this->logger,
            $config ?? new UpdateCheckConfig(),
        );
    }

    private function admin(string $email = 'admin@example.test'): User
    {
        return $this->reload($this->users->create($email, 'Admin', 'hash', true, new DateTimeImmutable()));
    }

    private function member(): User
    {
        $id = $this->users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());

        return $this->reload($id);
    }

    private function reload(User|int $user): User
    {
        $found = $this->users->findById($user instanceof User ? $user->id : $user);
        self::assertNotNull($found);

        return $found;
    }
}
