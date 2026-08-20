<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Signing;

use DateTimeImmutable;

/**
 * The `X-Metered-Signature` header: `t=<unix>,v1=<hmac>[,v1=<hmac>]`.
 *
 * The HMAC covers `"<t>.<raw body>"`, so the timestamp cannot be changed
 * without breaking it and a captured delivery stops verifying once the
 * receiver's tolerance passes. One `v1` per active secret, so a secret can be
 * rotated without the receiver and the sender switching at the same instant
 * (ADR-0011). docs/webhooks.md has the receiving side and the test vectors.
 */
final class Signature
{
    public const string HEADER = 'X-Metered-Signature';

    /**
     * @param non-empty-list<SecretKey> $secrets
     */
    public static function header(string $body, DateTimeImmutable $at, array $secrets): string
    {
        $timestamp = $at->getTimestamp();
        $header = 't=' . $timestamp;

        foreach ($secrets as $secret) {
            $header .= ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret->reveal());
        }

        return $header;
    }
}
