<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

/**
 * The delivery count decides when a message is dead-lettered.
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
