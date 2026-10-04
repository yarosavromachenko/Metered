<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Audit;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

/**
 * SHA-256 over the entry and the previous hash. The encoding is fixed (sorted
 * keys, explicit JSON flags, UTC with microseconds) so a PHP upgrade cannot
 * change existing hashes.
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
        ?string $organizationId,
    ): string {
        ksort($payload);

        $fields = [
            'prev' => $previousHash,
            'actor' => $actor,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload,
            'occurred_at' => $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
        ];

        // Absent for platform-chain entries, so their existing hashes still match.
        if ($organizationId !== null) {
            $fields['organization_id'] = $organizationId;
        }

        try {
            $canonical = json_encode(
                $fields,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw new RuntimeException('An audit payload must be JSON-encodable.', $e->getCode(), previous: $e);
        }

        return hash('sha256', $canonical);
    }
}
