<?php

declare(strict_types=1);

namespace App\Http;

/**
 * One request whose body is too large to hold in memory (Phase 39): an
 * instance backup on its way to a destination, or on its way back.
 *
 * The body is read from `$uploadFrom` as curl sends it, and a successful
 * response's body is written to `$downloadTo` as it arrives, so neither is
 * ever a PHP string. An unsuccessful response's body — an error document —
 * is still read into the response, within the same cap, so the caller can say
 * what went wrong.
 *
 * Its options replace the client's for this one request. They come from
 * `HttpClientOptions::forTransfers()`, the one place the operator's limits
 * are widened, and only for these transfers.
 */
final class Transfer
{
    public function __construct(
        public readonly HttpClientOptions $options,
        public readonly ?string $uploadFrom = null,
        public readonly ?string $downloadTo = null,
    ) {
    }
}
