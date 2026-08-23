<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Http;

/**
 * The resolver the operating system provides, asked for A and AAAA records.
 */
final readonly class SystemResolver implements Resolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
