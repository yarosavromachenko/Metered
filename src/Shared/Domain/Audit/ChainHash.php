<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Audit;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

/**
 * Computes an entry's link in the chain.
 *
 * Each hash covers the entry's own contents together with the previous hash,
 * so changing any entry invalidates every entry after it. Verification is
 * therefore a single pass, and tampering cannot be local.
 *
 * The encoding is pinned deliberately: sorted keys, unescaped slashes and
 * unicode, and timestamps in UTC with microseconds. A hash whose input depends
 * on PHP's default JSON flags would start failing on an upgrade, and nobody
 * would know whether the chain or the encoder had changed.
 */
final class ChainHash
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function compute(
        string $previousHash,
        string $actor,
        string $action,
        string $subjectType,
        ?string $subjectId,
        array $payload,
        DateTimeImmutable $occurredAt,
    ): string {
        ksort($payload);

        try {
            $canonical = json_encode(
                [
                    'prev' => $previousHash,
                    'actor' => $actor,
                    'action' => $action,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'payload' => $payload,
                    'occurred_at' => $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw new RuntimeException('An audit payload must be JSON-encodable.', $e->getCode(), previous: $e);
        }

        return hash('sha256', $canonical);
    }
}
