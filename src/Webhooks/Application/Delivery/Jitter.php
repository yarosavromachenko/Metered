<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Delivery;

/**
 * Retry jitter in thousandths of the wait; a port so tests can fix it.
 */
interface Jitter
{
    public function draw(): int;
}
