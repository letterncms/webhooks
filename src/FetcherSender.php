<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Cms\Support\SafeFetcher;

/**
 * Sends through the CMS's own SafeFetcher: public addresses only, checked after DNS and again on
 * the connection, pinned, no proxy, no redirects. The start of the answer is kept for the log.
 */
final class FetcherSender implements Sender
{
    public function send(string $url, string $body, array $headers): array
    {
        return SafeFetcher::post($url, $body, $headers, timeout: 8, maxBytes: 2000, truncate: true);
    }
}
