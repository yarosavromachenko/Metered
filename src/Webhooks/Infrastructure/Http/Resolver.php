<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Http;

/**
 * Turns a hostname into the addresses it points at right now. An interface so
 * that tests can answer differently on each call — which is exactly what a
 * rebinding attacker's DNS server does.
 */
interface Resolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses; empty when nothing resolves
     */
    public function resolve(string $host): array;
}
