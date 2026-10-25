<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use App\Http\GuardedClient;
use App\Notification\NotifierException;
use App\Service\ValidationException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Shared mechanics for the channels that post JSON to a URL somebody typed.
 *
 * Note the type of `$http`: the guarded client, not `ClientInterface`. That is
 * not a stylistic preference — binding to the interface would let the container
 * hand one of these the plain client, and the plain client does not vet
 * destinations. A user-supplied URL reaching an unguarded client is the exact
 * failure this phase exists to prevent, so the dependency names the class that
 * cannot be substituted for one.
 */
abstract class HttpNotifier
{
    public function __construct(
        protected readonly GuardedClient $http,
        protected readonly RequestFactoryInterface $requests,
        protected readonly StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @throws NotifierException
     */
    protected function postJson(
        string $url,
        array $payload,
        array $headers = [],
        bool $httpsOnly = false,
    ): ResponseInterface {
        $body = $this->encode($payload);

        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($body));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->dispatch($request, $httpsOnly);
    }

    /**
     * The same, for the services that want a form body rather than JSON.
     *
     * Pushover and Serverchan both document `application/x-www-form-urlencoded`
     * and nothing else. This exists rather than each of them hand-rolling a
     * request because the value in `postJson()` is not the encoding — it is the
     * `catch` below it, which is what turns a dead host into a failure recorded
     * against one channel instead of an exception escaping into the scheduler.
     * A notifier that built its own request would quietly lose that.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     * @throws NotifierException
     */
    protected function postForm(
        string $url,
        array $fields,
        array $headers = [],
        bool $httpsOnly = false,
    ): ResponseInterface {
        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            // RFC1738, PHP's default: `application/x-www-form-urlencoded`
            // encodes a space as `+`, not `%20`. RFC3986 would be correct for a
            // URL and wrong here, and the symptom would be titles arriving with
            // literal `%20` in them wherever a receiver decodes strictly.
            ->withBody($this->streams->createStream(http_build_query($fields)));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->dispatch($request, $httpsOnly);
    }

    /**
     * @throws NotifierException
     */
    private function dispatch(RequestInterface $request, bool $httpsOnly): ResponseInterface
    {
        try {
            return $this->http->send($request, $httpsOnly);
        } catch (ClientExceptionInterface $exception) {
            // Covers both a refused destination and a dead one. The user sees
            // the reason against the channel; neither case is worth taking the
            // rest of the run down for.
            throw NotifierException::transport($this->label(), $this->redact($exception->getMessage()), $exception);
        }
    }

    /**
     * Last chance to take a secret out of an error message before a human sees
     * it.
     *
     * The transport's own exceptions name the URL they failed on — see
     * `HttpClientException` — and that message is stored in
     * `notification_channels.last_error` and rendered on the settings page.
     * For every channel here but one that is harmless, because the secret
     * travels in a header or a body. Serverchan's sendkey is *in the path*, so
     * without this hook a single DNS failure would write a live credential into
     * the database and onto a page the user might screen-share.
     *
     * Default: nothing to hide. A channel whose URL carries a secret overrides
     * it.
     */
    protected function redact(string $message): string
    {
        return $message;
    }

    /**
     * A URL reduced to the part that is safe to show.
     *
     * Four of the services here put the credential in the path — Discord's and
     * Mattermost's webhook URLs, Telegram's `/bot<token>/`, Serverchan's
     * `<sendkey>.send`. For those, `describe()` cannot do what Gotify's does
     * and return the URL, because the string it would return next to the
     * channel name *is* the credential. The host answers the only question
     * `describe()` is really being asked — where does this one go? — and the
     * rest is dropped.
     */
    protected function summariseUrl(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($scheme) || !is_string($host) || $host === '') {
            return '';
        }

        $port = parse_url($url, PHP_URL_PORT);

        return $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');
    }

    /**
     * The one place a payload becomes bytes.
     *
     * Shared rather than inlined because a channel that signs its body must
     * sign the bytes that are actually sent. Two encodings with drifting flags
     * would produce a signature the receiver rejects, and the symptom — every
     * notification silently discarded at the far end — would point nowhere near
     * the cause.
     *
     * @param array<string, mixed> $payload
     * @throws NotifierException
     */
    protected function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw NotifierException::rejected($this->label(), 'the message could not be encoded');
        }
    }

    /**
     * Validate a URL the user typed, before it ever reaches the guard.
     *
     * This is a shape check, not a security one — the guard is what decides
     * where a request may go, and it does so at send time, when the answer is
     * current. Checking here as well means an obvious typo is reported next to
     * the field rather than as a failed notification a week later.
     *
     * @throws ValidationException
     */
    protected function validateUrl(string $field, string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw ValidationException::field($field, 'error.url.required');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::field($field, 'error.url.invalid');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::field($field, 'error.url.scheme');
        }

        if ((string) parse_url($url, PHP_URL_HOST) === '') {
            throw ValidationException::field($field, 'error.url.no_host');
        }

        return rtrim($url, '/');
    }

    abstract public function label(): string;
}
