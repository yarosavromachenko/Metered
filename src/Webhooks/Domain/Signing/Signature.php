<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Signing;

use DateTimeImmutable;

/**
 * `X-Metered-Signature: t=<unix>,v1=<hmac>[,v1=<hmac>]`, HMAC over
 * `"<t>.<raw body>"`, one `v1` per active secret (ADR-0011). Receiving side
 * and test vectors: docs/webhooks.md.
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
