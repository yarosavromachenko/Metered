<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Delivery;

/**
 * A draw for the retry schedule's jitter, in thousandths of the wait. A port
 * so that tests can pin it.
 */
interface Jitter
{
    public function draw(): int;
}
