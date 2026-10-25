<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\BlockedTargetException;
use App\Http\GuardedClient;
use App\Http\OutboundContext;
use App\Http\OutboundRefusal;
use App\Repository\LogoCacheRepository;
use App\Support\Clock;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches a site's own icon, once per domain.
 *
 * Three decisions are worth stating.
 *
 * **It asks the site, not a logo service.** Every icon service works by being
 * told which brands you are interested in, and the list of subscriptions a
 * household has is precisely the thing this application exists to keep. So the
 * request goes to the domain the user typed and nowhere else.
 *
 * **It goes through the guarded client, https only.** The URL came from a
 * form, which makes it exactly the kind of address the SSRF protections exist
 * for: a fetch of `http://169.254.169.254/...` would otherwise be this server
 * reading its own cloud credentials on a stranger's behalf.
 *
 * **A result is cached per domain, including a failure.** Twenty households
 * with the same streaming service cost one request between them, and a domain
 * with no icon is not asked again until the negative entry expires. The cached
 * file is copied into each subscription's own logo rather than shared, so
 * removing one subscription's logo cannot blank another's.
 */
final class LogoFetcher
{
    /** Tried in order; the first that is an image wins. */
    private const CANDIDATES = [
        '/apple-touch-icon.png',
        '/apple-touch-icon-precomposed.png',
        '/favicon.ico',
        '/favicon.png',
    ];

    /** How long a found icon is reused before the site is asked again. */
    private const SUCCESS_TTL = '+30 days';

    /** How long a domain with no icon is left alone. */
    private const FAILURE_TTL = '+7 days';

    private const MAX_BYTES = 512 * 1024;

    /** The most time the four icon paths may take between them, in seconds. */
    private const BUDGET_SECONDS = 6;

    public function __construct(
        private readonly GuardedClient $http,
        private readonly RequestFactoryInterface $requests,
        private readonly LogoCacheRepository $cache,
        private readonly LogoStorage $logos,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $cacheDirectory,
        private readonly ?OutboundContext $context = null,
    ) {
    }

    /**
     * The stored logo path for a website, or null when nothing usable was
     * found. Never throws: a missing logo is a cosmetic disappointment, and a
     * subscription must save without one.
     */
    public function fetchFor(?string $websiteUrl, ?int $actorUserId = null): ?string
    {
        $work = fn (): ?string => $this->fetchDomain($websiteUrl);

        return $this->context === null
            ? $work()
            : $this->context->within(OutboundContext::LOGO, $actorUserId, $work);
    }

    private function fetchDomain(?string $websiteUrl): ?string
    {
        $domain = self::domainOf($websiteUrl);
        if ($domain === null) {
            return null;
        }

        $now = $this->clock->now();
        $entry = $this->cache->find($domain);

        if ($entry !== null) {
            if ($entry['failed_at'] !== null && $entry['failed_at']->modify(self::FAILURE_TTL) > $now) {
                return null;
            }

            if (
                $entry['cached_path'] !== null
                && $entry['fetched_at'] !== null
                && $entry['fetched_at']->modify(self::SUCCESS_TTL) > $now
                && is_file($entry['cached_path'])
            ) {
                // The hit that makes this worth having: no request at all.
                return $this->logos->storeFile($entry['cached_path']);
            }
        }

        $refusedByMode = false;
        $cached = $this->download($domain, $refusedByMode);

        if ($cached === null) {
            // The instance's outbound mode said no: the site was never asked,
            // so it is not a site with no icon. Remembering it as one would
            // keep the logo away for a week after the mode is loosened.
            if (!$refusedByMode) {
                $this->cache->recordFailure($domain, $now);
            }

            return null;
        }

        $this->cache->recordSuccess($domain, $cached, $now);

        return $this->logos->storeFile($cached);
    }

    /**
     * The registrable host of a URL, lower-cased, or null when there is not one.
     *
     * Public and static because the same normalisation decides cache identity
     * and is worth testing on its own.
     */
    public static function domainOf(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (!str_contains($url, '://')) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        // A host that is not a name — an address, or something with no dot in
        // it — is not a domain a public icon lives on.
        return str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false ? $host : null;
    }

    /**
     * Try the conventional icon paths until one answers with an image.
     *
     * @return string|null The path of the cached file.
     */
    private function download(string $domain, bool &$refusedByMode): ?string
    {
        // A site that does not answer must not hold a save for the sum of
        // four timeouts (K55): once the budget is spent, the rest are skipped.
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        foreach (self::CANDIDATES as $path) {
            if (microtime(true) >= $deadline || $refusedByMode) {
                break;
            }

            $body = $this->get('https://' . $domain . $path, $refusedByMode);

            if ($body !== null && $this->looksLikeAnImage($body)) {
                $stored = $this->writeToCache($domain, $body);

                if ($stored !== null) {
                    return $stored;
                }
            }
        }

        return null;
    }

    private function get(string $url, bool &$refusedByMode): ?string
    {
        try {
            $response = $this->http->send(
                $this->requests->createRequest('GET', $url)->withHeader('Accept', 'image/*'),
                httpsOnly: true,
            );
        } catch (BlockedTargetException $exception) {
            if (OutboundRefusal::reasonOf($exception) !== null) {
                $refusedByMode = true;

                return null;
            }

            // Worth a line in the log: somebody's URL resolved somewhere this
            // server is not allowed to go, and that is not a routine miss.
            $this->logger->info('Logo fetch refused a destination', [
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        } catch (Throwable) {
            // Any transport failure at all is just "no logo today".
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = (string) $response->getBody();

        return $body === '' || strlen($body) > self::MAX_BYTES ? null : $body;
    }

    /**
     * Magic bytes, not the Content-Type header: a server that says `image/png`
     * over an HTML error page is common enough to be the normal case.
     */
    private function looksLikeAnImage(string $body): bool
    {
        $info = @getimagesizefromstring($body);

        return is_array($info) && $info[2] !== 0;
    }

    private function writeToCache(string $domain, string $body): ?string
    {
        if (
            !is_dir($this->cacheDirectory)
            && !mkdir($this->cacheDirectory, 0o775, true)
            && !is_dir($this->cacheDirectory)
        ) {
            return null;
        }

        // Named by the domain's hash, so the cache file for a domain is always
        // the same file and a re-fetch replaces it rather than accumulating.
        $path = rtrim($this->cacheDirectory, '/') . '/' . hash('sha256', $domain) . '.img';

        return file_put_contents($path, $body) === false ? null : $path;
    }
}
