<?php

/**
 * Container wiring.
 *
 * Most classes are autowired; the definitions below are the ones that need a
 * decision — a concrete implementation for an interface, or a constructor
 * argument that comes from configuration rather than from another service.
 */

declare(strict_types=1);

use App\Application\AppVersion;
use App\Application\Twig\AppExtension;
use App\Service\Update\UpdateCheckConfig;
use App\Service\Update\UpdateCheckService;
use App\Controller\SetupController;
use App\Http\DnsResolver;
use App\Http\GuardedClient;
use App\Http\GuardedHttpClient;
use App\Http\HttpClient;
use App\Http\HttpClientOptions;
use App\Http\SystemDnsResolver;
use App\Http\TrustedTargets;
use App\I18n\CatalogLoader;
use App\I18n\LocaleContext;
use App\I18n\Locales;
use App\I18n\Translator;
use App\Persistence\Database;
use App\Persistence\PdoSessionHandler;
use App\Persistence\Platform;
use App\Persistence\PlatformFactory;
use App\Repository\AuthAttemptRepository;
use App\Repository\ExchangeRateRepository;
use App\Controller\Ops\MetricsController;
use App\Repository\LogoCacheRepository;
use App\Repository\UserRepository;
use App\Service\MetricsService;
use App\Repository\NotificationLogRepository;
use App\Security\CsrfTokenManager;
use App\Security\KeyRing;
use App\Support\Settings;
use App\Security\SecretCipher;
use App\Security\Session;
use App\Security\SessionInterface;
use App\Service\Auth\WebAuthnService;
use App\Service\AuthService;
use App\Service\LogoFetcher;
use App\Service\ExchangeRate\ExchangeRateHostProvider;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FixerProvider;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Notification\Channel\DiscordNotifier;
use App\Notification\Channel\EmailNotifier;
use App\Notification\Channel\GotifyNotifier;
use App\Notification\Channel\MattermostNotifier;
use App\Notification\Channel\NtfyNotifier;
use App\Notification\Channel\PushoverNotifier;
use App\Notification\Channel\PushplusNotifier;
use App\Notification\Channel\ServerchanNotifier;
use App\Notification\Channel\SlackNotifier;
use App\Notification\Channel\TelegramNotifier;
use App\Notification\Channel\WebhookNotifier;
use App\Notification\NotifierRegistry;
use App\Service\ExchangeRateService;
use App\Service\InstanceSettingsService;
use App\Service\Notification\AlertScanner;
use App\Service\Notification\PriceChangeScanner;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationRateLimiter;
use App\Service\Notification\ReminderRunner;
use App\Service\TrustedHostService;
use App\Application\Api\OpenApiDocument;
use App\Service\AccountService;
use App\Service\AttachmentStorage;
use App\Service\AvatarStorage;
use App\Service\HouseholdMemberService;
use App\Service\BackupService;
use App\Service\Backup\BackupFiles;
use App\Service\Backup\InstanceBackupService;
use App\Service\ImportService;
use App\Service\LogoStorage;
use App\Service\SettingsScreenService;
use App\Service\SetupService;
use App\Service\MailerService;
use App\Service\PasswordResetService;
use App\Service\RateLimiter;
use App\Support\AssetVersion;
use App\Support\BuildManifest;
use App\Support\IconSprite;
use App\Support\ExternalAssetScanner;
use App\Support\Clock;
use App\Support\MoneyFormatter;
use App\Support\NumberFormat;
use App\Support\SystemClock;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;
use Slim\Views\Twig;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use App\Http\GuardedTransferClient;
use App\Http\UrlGuard;
use App\Repository\BackupDestinationRepository;
use App\Service\AuditLogService;
use App\Service\Backup\BackupAlertService;
use App\Service\Backup\BackupDestinationService;
use App\Service\Backup\Destination\BackupDestination;
use App\Service\Backup\Destination\BackupDestinationFactory;
use App\Service\Backup\OffsiteBackupService;
use App\Support\AfterResponse;
use App\Support\ShutdownAfterResponse;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * @param array<string, mixed>|null $settings Fixed settings, for a test that builds
 *        its own container. The application passes none: `settings` is then a
 *        factory, read from the environment when the container starts, so a
 *        compiled container never holds them (App\Support\Settings).
 */
return static function (ContainerBuilder $builder, ?array $settings = null): void {
    $builder->addDefinitions([
        'settings' => $settings ?? factory([Settings::class, 'load']),

        // ------------------------------------------------------------------
        // Persistence
        // ------------------------------------------------------------------
        Platform::class => static fn (ContainerInterface $c): Platform => PlatformFactory::create(
            $c->get('settings')['database']['driver'],
        ),

        Database::class => static fn (ContainerInterface $c): Database => new Database(
            $c->get('settings')['database'],
            $c->get(Platform::class),
        ),

        PdoSessionHandler::class => static fn (ContainerInterface $c): PdoSessionHandler => new PdoSessionHandler(
            $c->get(Database::class),
            $c->get('settings')['session']['lifetime'],
            $c->get('settings')['session']['browser_lifetime'],
        ),

        // ------------------------------------------------------------------
        // HTTP
        // ------------------------------------------------------------------
        RequestFactoryInterface::class => autowire(RequestFactory::class),
        ResponseFactoryInterface::class => autowire(ResponseFactory::class),

        HttpClientOptions::class => static function (ContainerInterface $c): HttpClientOptions {
            $http = $c->get('settings')['http'];

            return new HttpClientOptions(
                timeoutSeconds: $http['timeout'],
                connectTimeoutSeconds: $http['connect_timeout'],
                maxResponseBytes: $http['max_response_bytes'],
                maxRedirects: $http['max_redirects'],
                userAgent: $http['user_agent'],
                httpProxy: $http['proxy'] !== '' ? $http['proxy'] : null,
                httpsProxy: $http['https_proxy'] !== '' ? $http['https_proxy'] : null,
                noProxy: $http['no_proxy'],
            );
        },

        StreamFactoryInterface::class => autowire(StreamFactory::class),
        UriFactoryInterface::class => autowire(UriFactory::class),

        // Every outbound call in the application resolves to this one client.
        //
        // Two clients, and which one a class asks for is a security decision
        // rather than a preference. `ClientInterface` is the plain one, for
        // destinations that come from configuration — the exchange-rate
        // providers. `GuardedHttpClient` is named explicitly by anything
        // fetching a URL a user typed, and it cannot be substituted for the
        // plain one by a container definition, because nothing binds it to an
        // interface the plain one also satisfies.
        ClientInterface::class => autowire(HttpClient::class),

        DnsResolver::class => autowire(SystemDnsResolver::class),

        // The type the notifiers ask for. Bound to the guarding implementation
        // and to nothing else; `HttpClient` does not implement it.
        GuardedClient::class => get(GuardedHttpClient::class),

        // The allowlist the SSRF guard consults is the administrator's table.
        TrustedTargets::class => get(TrustedHostService::class),

        // Backup destinations (Phase 39): the same guard and client, with
        // bodies streamed from and to files and the transfer limits in place
        // of HTTP_*: a backup is far larger, and slower, than anything else.
        GuardedTransferClient::class => static fn (ContainerInterface $c): GuardedTransferClient
            => new GuardedTransferClient(
                $c->get(HttpClient::class),
                $c->get(UrlGuard::class),
                $c->get(HttpClientOptions::class)->forTransfers(
                    $c->get('settings')['backup']['transfer_timeout'],
                    BackupDestination::MAX_BYTES,
                ),
            ),

        // ------------------------------------------------------------------
        // Exchange rates
        // ------------------------------------------------------------------
        // Order matters: the first entry is the default for a new instance, and
        // it is the free, keyless one on purpose — currency conversion works
        // out of the box without the operator signing up to anything. Fixer is
        // offered but never first.
        ExchangeRateProviderRegistry::class => static fn (ContainerInterface $c): ExchangeRateProviderRegistry
            => new ExchangeRateProviderRegistry([
                $c->get(FrankfurterProvider::class),
                $c->get(ExchangeRateHostProvider::class),
                $c->get(FixerProvider::class),
            ]),

        ExchangeRateService::class => static function (ContainerInterface $c): ExchangeRateService {
            $rates = $c->get('settings')['rates'];

            return new ExchangeRateService(
                $c->get(ExchangeRateRepository::class),
                $c->get(ExchangeRateProviderRegistry::class),
                $c->get(InstanceSettingsService::class),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
                $rates['ttl_seconds'],
                $rates['retry_seconds'],
                $rates['api_key'],
            );
        },

        // ------------------------------------------------------------------
        // Update check
        // ------------------------------------------------------------------
        // The shared client with the instance's proxy and narrower limits: a
        // release is a few kilobytes of JSON, and the lazy check runs while an
        // administrator waits for their dashboard. Its own key, so a test can
        // put a client there that fails if it is ever called.
        UpdateCheckService::HTTP_CLIENT => static fn (ContainerInterface $c): ClientInterface => new HttpClient(
            $c->get(ResponseFactoryInterface::class),
            $c->get(StreamFactoryInterface::class),
            $c->get(HttpClientOptions::class)->narrowedTo(5, 3, 65536),
        ),

        UpdateCheckConfig::class => static fn (): UpdateCheckConfig => UpdateCheckConfig::fromEnvironment(),

        UpdateCheckService::class => autowire()->constructorParameter('http', get(UpdateCheckService::HTTP_CLIENT)),

        // ------------------------------------------------------------------
        // Notifications
        // ------------------------------------------------------------------
        // The registry is the only list of channel types in the application.
        // Adding one means adding a class and a line here; nothing else in the
        // codebase names a channel.
        NotifierRegistry::class => static fn (ContainerInterface $c): NotifierRegistry => new NotifierRegistry([
            $c->get(EmailNotifier::class),
            $c->get(DiscordNotifier::class),
            $c->get(GotifyNotifier::class),
            $c->get(MattermostNotifier::class),
            $c->get(NtfyNotifier::class),
            $c->get(PushoverNotifier::class),
            $c->get(PushplusNotifier::class),
            $c->get(ServerchanNotifier::class),
            $c->get(SlackNotifier::class),
            $c->get(TelegramNotifier::class),
            $c->get(WebhookNotifier::class),
        ]),

        NotificationRateLimiter::class => static function (ContainerInterface $c): NotificationRateLimiter {
            $limits = $c->get('settings')['notifications'];

            return new NotificationRateLimiter(
                $c->get(NotificationLogRepository::class),
                $c->get(Clock::class),
                $limits['max_per_user_per_hour'],
                $limits['max_per_subject_per_day'],
            );
        },

        NotificationDispatcher::class => autowire()->constructorParameter(
            'maxAttempts',
            factory(static fn (ContainerInterface $c): int => $c->get('settings')['notifications']['max_attempts']),
        ),

        // The links in a notification have to be absolute: the message is read
        // somewhere that has no idea what host the application is on.
        AlertScanner::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        PriceChangeScanner::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        // The feed's address is pasted into a calendar app elsewhere, so it is
        // built from APP_URL rather than from whatever host this request came
        // in on — behind a TLS-terminating proxy that would be the inside one.
        \App\Controller\CalendarController::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        BackupAlertService::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        ReminderRunner::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        // ------------------------------------------------------------------
        // Session & security
        // ------------------------------------------------------------------
        SessionInterface::class => static function (ContainerInterface $c): SessionInterface {
            $session = $c->get('settings')['session'];

            return new Session([
                'name' => $session['name'],
                'lifetime' => $session['lifetime'],
                'secure' => $session['secure'],
                'samesite' => $session['samesite'],
                'path' => $session['path'],
                'domain' => $session['domain'],
            ]);
        },

        CsrfTokenManager::class => autowire()->constructorParameter('session', get(SessionInterface::class)),

        // Secrets that have to be stored recoverably are stored encrypted.
        // KeyRing resolves the keys (SECRETS_KEY, then TOTP_ENCRYPTION_KEY,
        // then SESSION_KEY, plus the previous keys); this cipher is the
        // two-factor one, and the channel and rate-key ciphers are built from
        // the same ring with their own purposes.
        KeyRing::class => static fn (ContainerInterface $c): KeyRing => KeyRing::fromSettings($c->get('settings')),

        SecretCipher::class => static fn (ContainerInterface $c): SecretCipher => new SecretCipher(
            $c->get(KeyRing::class),
            SecretCipher::PURPOSE_TOTP,
        ),

        // The relying party is this deployment. Both values come from
        // configuration: the name is what the authenticator shows the user, and
        // the id is derived from APP_URL, which is the address the credential is
        // bound to.
        WebAuthnService::class => autowire()
            ->constructorParameter(
                'appUrl',
                factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
            )
            ->constructorParameter(
                'relyingPartyName',
                factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['name']),
            ),

        // Everything in the application reads "now" through this one clock,
        // in UTC, so stored timestamps do not depend on the server's locale.
        Clock::class => static fn (): Clock => new SystemClock(new DateTimeZone('UTC')),

        // Read from the VERSION file and the environment when first asked,
        // never through the settings array, so a compiled container cannot
        // keep naming the release it was built under. See AppVersion.
        AppVersion::class => static fn (): AppVersion => AppVersion::fromEnvironment(dirname(__DIR__)),

        // ------------------------------------------------------------------
        // Services needing configuration values
        // ------------------------------------------------------------------
        RateLimiter::class => static function (ContainerInterface $c): RateLimiter {
            $auth = $c->get('settings')['auth'];

            return new RateLimiter(
                $c->get(AuthAttemptRepository::class),
                $c->get(Clock::class),
                $auth['max_attempts_per_account'],
                $auth['max_attempts_per_ip'],
                $auth['throttle_window'],
                $auth['lockout_seconds'],
            );
        },

        MailerInterface::class => static function (ContainerInterface $c): MailerInterface {
            $mail = $c->get('settings')['mail'];

            $scheme = match ($mail['encryption']) {
                'tls', 'ssl' => 'smtps',
                default => 'smtp',
            };

            $credentials = $mail['user'] !== ''
                ? rawurlencode($mail['user']) . ':' . rawurlencode($mail['password']) . '@'
                : '';

            $dsn = sprintf('%s://%s%s:%d', $scheme, $credentials, $mail['host'], $mail['port']);
            if ($scheme === 'smtp') {
                // A local relay such as Mailpit does not offer STARTTLS.
                $dsn .= '?verify_peer=0';
            }

            return new Mailer(Transport::fromDsn($dsn));
        },

        MailerService::class => static function (ContainerInterface $c): MailerService {
            $mail = $c->get('settings')['mail'];

            return new MailerService(
                $c->get(MailerInterface::class),
                $c->get(LoggerInterface::class),
                $mail['from_address'],
                $mail['from_name'],
            );
        },

        AuthService::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        PasswordResetService::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        // The wizard's second step reports the mail relay the instance will
        // actually use, rather than asking the operator to go and check.
        SetupController::class => autowire()
            ->constructorParameter(
                'smtpHost',
                factory(static function (ContainerInterface $c): string {
                    $mail = $c->get('settings')['mail'];

                    return sprintf('%s:%d', $mail['host'], $mail['port']);
                }),
            )
            ->constructorParameter(
                'mailFrom',
                factory(static fn (ContainerInterface $c): string => $c->get('settings')['mail']['from_address']),
            ),

        // The Instance tab reports the mail relay and the metrics endpoint as
        // the environment configures them: read-only, and never the password
        // or the token themselves.
        SettingsScreenService::class => autowire()
            ->constructorParameter(
                'smtpHost',
                factory(static fn (ContainerInterface $c): string => $c->get('settings')['mail']['host']),
            )
            ->constructorParameter(
                'smtpPort',
                factory(static fn (ContainerInterface $c): int => $c->get('settings')['mail']['port']),
            )
            ->constructorParameter(
                'smtpEncryption',
                factory(static fn (ContainerInterface $c): string => $c->get('settings')['mail']['encryption']),
            )
            ->constructorParameter(
                'smtpAuthenticates',
                factory(static fn (ContainerInterface $c): bool => $c->get('settings')['mail']['user'] !== ''),
            )
            ->constructorParameter(
                'metricsEnabled',
                factory(static fn (ContainerInterface $c): bool => $c->get('settings')['metrics']['token'] !== ''),
            ),

        LogoStorage::class => static function (ContainerInterface $c): LogoStorage {
            $uploads = $c->get('settings')['uploads'];

            return new LogoStorage($uploads['logo_directory'], $uploads['logo_max_bytes']);
        },

        // ------------------------------------------------------------------
        // Phase 5: interoperability
        //
        // Each of these takes a filesystem path from configuration, which is
        // the only reason it is listed here rather than autowired.
        // ------------------------------------------------------------------
        AttachmentStorage::class => static function (ContainerInterface $c): AttachmentStorage {
            $uploads = $c->get('settings')['uploads'];

            return new AttachmentStorage(
                $uploads['attachment_directory'],
                $uploads['attachment_max_bytes'],
            );
        },

        // ------------------------------------------------------------------
        // Phase 15: household membership
        // ------------------------------------------------------------------
        AvatarStorage::class => static function (ContainerInterface $c): AvatarStorage {
            $uploads = $c->get('settings')['uploads'];

            return new AvatarStorage($uploads['avatar_directory'], $uploads['avatar_max_bytes']);
        },

        // Both send mail with a link in it, to somebody who is not looking at
        // the application when they read it.
        HouseholdMemberService::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        AccountService::class => autowire()->constructorParameter(
            'appUrl',
            factory(static fn (ContainerInterface $c): string => $c->get('settings')['app']['url']),
        ),

        ImportService::class => autowire(ImportService::class)
            ->constructorParameter(
                'directory',
                factory(static fn (ContainerInterface $c): string => $c->get('settings')['paths']['imports']),
            ),

        BackupService::class => autowire(BackupService::class)
            ->constructorParameter(
                'logoDirectory',
                factory(
                    static fn (ContainerInterface $c): string => $c->get('settings')['uploads']['logo_directory'],
                ),
            ),

        // ------------------------------------------------------------------
        // Phase 38: instance backups
        //
        // The three directories whose files a backup carries, and the
        // directory and key from the environment. The key reaches the service
        // and nothing else.
        // ------------------------------------------------------------------
        BackupFiles::class => static function (ContainerInterface $c): BackupFiles {
            $uploads = $c->get('settings')['uploads'];

            return new BackupFiles([
                'attachments' => $uploads['attachment_directory'],
                'avatars' => $uploads['avatar_directory'],
                'logos' => $uploads['logo_directory'],
            ]);
        },

        BackupDestinationService::class => static function (ContainerInterface $c): BackupDestinationService {
            $settings = $c->get('settings');

            // A Directory destination may not be anywhere Renovo keeps its own
            // files: its pruning, or the web server, would reach the copies.
            return new BackupDestinationService(
                $c->get(BackupDestinationRepository::class),
                $c->get(BackupDestinationFactory::class),
                $c->get(InstanceSettingsService::class),
                $c->get(AuditLogService::class),
                array_values(array_unique([
                    $settings['paths']['root'],
                    $settings['backup']['directory'],
                    $settings['uploads']['attachment_directory'],
                    $settings['uploads']['avatar_directory'],
                    $settings['uploads']['logo_directory'],
                ])),
            );
        },

        AfterResponse::class => autowire(ShutdownAfterResponse::class),

        OffsiteBackupService::class => autowire(OffsiteBackupService::class)
            ->constructorParameter(
                'directory',
                factory(static fn (ContainerInterface $c): string => $c->get('settings')['backup']['directory']),
            )
            // Longer than any transfer can run, so a copy being sent is never
            // taken by a second process; a crashed one is retried after it.
            ->constructorParameter(
                'leaseSeconds',
                factory(
                    static fn (ContainerInterface $c): int => $c->get('settings')['backup']['transfer_timeout'] + 600,
                ),
            ),

        InstanceBackupService::class => autowire(InstanceBackupService::class)
            ->constructorParameter(
                'directory',
                factory(static fn (ContainerInterface $c): string => $c->get('settings')['backup']['directory']),
            )
            ->constructorParameter(
                'keyHex',
                factory(static fn (ContainerInterface $c): string => $c->get('settings')['backup']['key']),
            ),

        OpenApiDocument::class => static fn (ContainerInterface $c): OpenApiDocument => new OpenApiDocument(
            $c->get('settings')['paths']['openapi'],
        ),

        MoneyFormatter::class => autowire(MoneyFormatter::class),

        NumberFormat::class => autowire(NumberFormat::class),

        AssetVersion::class => static fn (ContainerInterface $c): AssetVersion => new AssetVersion(
            $c->get('settings')['paths']['public'],
        ),

        // The built assets' side of the same question. AssetVersion stamps a
        // modification time onto a file it did not produce; BuildManifest
        // reads the name the bundler already hashed. A template uses whichever
        // matches the file it is asking for — `bundle()` for anything the
        // build emits, `asset()` for the hand-written stylesheet and the
        // vendored htmx that it does not.
        BuildManifest::class => static fn (ContainerInterface $c): BuildManifest => new BuildManifest(
            $c->get('settings')['paths']['build_manifest'],
        ),

        // The sprite the same build emits. Strict outside production, so an
        // icon name nobody added to `assets/theme/icons.json` fails the page
        // that uses it in development and in the tests, where someone will
        // see it, rather than drawing nothing in front of a user.
        IconSprite::class => static fn (ContainerInterface $c): IconSprite => new IconSprite(
            $c->get('settings')['paths']['build'] . '/icons.json',
            $c->get('settings')['app']['env'] !== 'production',
            $c->get(LoggerInterface::class),
        ),

        ExternalAssetScanner::class => static fn (ContainerInterface $c): ExternalAssetScanner
            => new ExternalAssetScanner($c->get('settings')['paths']['root']),

        // The logo fetcher takes the *guarded* client, never the plain one:
        // the address comes from a form, which is the whole reason the guard
        // exists. The type makes the substitution impossible rather than
        // merely discouraged — see GuardedClient.
        LogoFetcher::class => static function (ContainerInterface $c): LogoFetcher {
            $settings = $c->get('settings');

            return new LogoFetcher(
                $c->get(GuardedClient::class),
                $c->get(RequestFactoryInterface::class),
                $c->get(LogoCacheRepository::class),
                $c->get(LogoStorage::class),
                $c->get(Clock::class),
                $c->get(LoggerInterface::class),
                $settings['uploads']['logo_cache_directory'],
            );
        },

        // ------------------------------------------------------------------
        // Translation
        //
        // The locale context is a single mutable object for the life of the
        // request, like the audit layer's RequestContextHolder: the middleware
        // writes it, everything that renders a sentence or formats a number
        // reads it.
        //
        // Strict mode is on under APP_ENV=test and nowhere else. In a test a
        // key with no message is a bug worth failing over; in production it is
        // a blemish, and taking a page down over a missing string would turn
        // a typo into an outage.
        // ------------------------------------------------------------------
        CatalogLoader::class => static fn (ContainerInterface $c): CatalogLoader => new CatalogLoader(
            $c->get('settings')['paths']['translations'],
        ),

        LocaleContext::class => static fn (ContainerInterface $c): LocaleContext => new LocaleContext(
            $c->get('settings')['app']['locale'],
        ),

        Locales::class => static fn (ContainerInterface $c): Locales => new Locales(
            $c->get(CatalogLoader::class),
            $c->get('settings')['app']['locale'],
        ),

        Translator::class => static fn (ContainerInterface $c): Translator => new Translator(
            $c->get(CatalogLoader::class),
            $c->get(LocaleContext::class),
            strict: $c->get('settings')['app']['env'] === 'test',
        ),

        MetricsController::class => static fn (ContainerInterface $c): MetricsController => new MetricsController(
            $c->get(MetricsService::class),
            $c->get(SessionInterface::class),
            $c->get(UserRepository::class),
            $c->get('settings')['metrics']['token'],
        ),

        // ------------------------------------------------------------------
        // Logging & views
        // ------------------------------------------------------------------
        LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
            $settings = $c->get('settings');
            $logger = new Logger($settings['app']['name']);

            $directory = $settings['paths']['logs'];
            if (!is_dir($directory)) {
                @mkdir($directory, 0o775, true);
            }

            // stderr in containers, a file when that is not writable.
            $target = is_writable($directory) ? $directory . '/app.log' : 'php://stderr';
            $logger->pushHandler(new StreamHandler($target, $settings['app']['debug'] ? Level::Debug : Level::Info));

            return $logger;
        },

        Twig::class => static function (ContainerInterface $c): Twig {
            $settings = $c->get('settings');

            $twig = Twig::create($settings['paths']['templates'], [
                'cache' => $settings['app']['debug'] ? false : $settings['paths']['cache'] . '/twig',
                'debug' => $settings['app']['debug'],
                'strict_variables' => false,
                // Every template output is escaped unless a value is
                // explicitly marked safe.
                'autoescape' => 'html',
            ]);

            $twig->addExtension($c->get(AppExtension::class));

            return $twig;
        },
    ]);
};
