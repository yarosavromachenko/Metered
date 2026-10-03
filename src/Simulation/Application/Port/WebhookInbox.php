<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use SensitiveParameter;

/**
 * The demo webhook receiver; it is given the signing secrets to verify deliveries.
 */
interface WebhookInbox
{
    public function url(string $mode): string;

    public function trust(#[SensitiveParameter] string $secret): void;
}
