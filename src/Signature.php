<?php

declare(strict_types=1);

namespace Cms\Webhooks;

/**
 * How a receiver knows a delivery is ours and fresh: `t=<unix time>,v1=<hex>`, where the hex is
 * HMAC-SHA256 of "<t>.<body>" with the endpoint's secret.
 */
final class Signature
{
    public static function header(string $secret, string $body, int $time): string
    {
        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret);
    }

    /** What a receiver does: the same sum, compared in constant time, no older than $tolerance seconds. */
    public static function verify(string $secret, string $body, string $header, int $now, int $tolerance = 300): bool
    {
        if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m) || abs($now - (int) $m[1]) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }
}
