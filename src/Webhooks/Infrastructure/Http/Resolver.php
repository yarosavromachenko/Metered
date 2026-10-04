<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Http;

/**
 * An interface so tests can simulate DNS rebinding.
 */
interface Resolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when nothing resolves
     */
    public function resolve(string $host): array;
}
