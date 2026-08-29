<?php

declare(strict_types=1);

/**
 * Verifies an X-Metered-Signature header the way docs/webhooks.md tells a
 * receiver to. The demo receiver runs this very function, and the test suite
 * checks it against the published vectors, so the documented example is code
 * that is known to work.
 *
 * $now is for checking the vectors, which are from a fixed moment; a receiver
 * leaves it out and uses the clock.
 */
function verify(string $body, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
{
    $parts = [];
    foreach (explode(',', $header) as $piece) {
        [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
        $parts[$k][] = $v;
    }

    $timestamp = (int) ($parts['t'][0] ?? 0);
    if (abs(($now ?? time()) - $timestamp) > $tolerance) {
        return false; // too old, or the clock is wrong: reject either way
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    foreach ($parts['v1'] ?? [] as $candidate) {
        if (hash_equals($expected, $candidate)) {
            return true;
        }
    }

    return false;
}
