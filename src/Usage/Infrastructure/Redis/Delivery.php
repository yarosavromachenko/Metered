<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

/**
 * One message as the consumer group handed it over: its position in the
 * stream, its fields, and how many times it has been delivered.
 *
 * The delivery count is the poison detector. A message that keeps being
 * redelivered is a message something keeps failing on, and after enough
 * attempts the right answer is to set it aside rather than to keep the whole
 * stream behind it.
 */
final readonly class Delivery
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public string $id,
        public array $fields,
        public int $deliveries,
    ) {}
}
