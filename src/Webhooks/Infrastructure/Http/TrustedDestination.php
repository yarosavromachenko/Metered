<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Http;

use RuntimeException;

/**
 * The one private destination a local stack may deliver to: the demo's
 * webhook receiver, named exactly — one host, one port, nothing wider.
 *
 * Everything else the SSRF guard does still applies to it: the host is
 * resolved once, the connection is pinned to that address, redirects are not
 * followed. Only the question "is this address public?" is skipped, and only
 * for this host and port. Another private host, or the same host on another
 * port, is refused as before.
 *
 * It exists for a local receiver, so it is honoured only in the local and demo
 * environments. Set anywhere else, the application refuses to boot: a
 * forgotten line in a production .env must fail loudly, not open a door
 * (ADR-0011).
 */
final readonly class TrustedDestination
{
    /** @var list<string> */
    private const array ENVIRONMENTS = ['local', 'demo'];

    private function __construct(
        public string $host,
        public int $port,
    ) {}

    public static function fromConfig(?string $value, string $environment): ?self
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! in_array($environment, self::ENVIRONMENTS, true)) {
            throw new RuntimeException(sprintf(
                'WEBHOOKS_TRUSTED_DESTINATION is set in the "%s" environment. It exists for a local webhook receiver and is refused anywhere but %s.',
                $environment,
                implode(' and ', self::ENVIRONMENTS),
            ));
        }

        if (preg_match('/^([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?):(\d{1,5})$/i', $value, $parts) !== 1 || (int) $parts[2] < 1 || (int) $parts[2] > 65_535) {
            throw new RuntimeException(sprintf(
                'WEBHOOKS_TRUSTED_DESTINATION must name one host and one port, such as "webhook-receiver:8080"; "%s" does not.',
                $value,
            ));
        }

        return new self(strtolower($parts[1]), (int) $parts[2]);
    }

    public function matches(string $host, int $port): bool
    {
        return strtolower($host) === $this->host && $port === $this->port;
    }
}
