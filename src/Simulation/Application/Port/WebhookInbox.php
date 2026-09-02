<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use SensitiveParameter;

/**
 * The demo's webhook receiver: where seeded endpoints point, and where their
 * signing secrets go so it can verify what it is sent.
 */
interface WebhookInbox
{
    public function url(string $mode): string;

    public function trust(#[SensitiveParameter] string $secret): void;
}
