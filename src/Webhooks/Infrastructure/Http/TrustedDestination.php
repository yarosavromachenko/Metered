<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Http;

use RuntimeException;

/**
 * The demo webhook receiver: one exact host and port exempt from the "public
 * address" check; pinning and no-redirect still apply. Only in local and
 * demo; configured anywhere else, the application refuses to boot (ADR-0011).
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
