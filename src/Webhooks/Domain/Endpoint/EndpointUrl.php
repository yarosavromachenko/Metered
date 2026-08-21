<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Endpoint;

use Metered\Webhooks\Domain\Exception\InvalidEndpoint;
use Stringable;

/**
 * Where an endpoint's deliveries go. Checked for shape here; where it points
 * is checked again at every delivery, because a hostname can resolve anywhere
 * by then (ADR-0011).
 */
final readonly class EndpointUrl implements Stringable
{
    public const int LIMIT = 2048;

    private function __construct(
        public string $value,
        public string $scheme,
        public string $host,
        public int $port,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @param bool $allowHttp plain HTTP, which only a local environment accepts
     */
    public static function fromString(string $url, bool $allowHttp = false): self
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > self::LIMIT) {
            throw InvalidEndpoint::url(sprintf('it must be 1 to %d characters', self::LIMIT));
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw InvalidEndpoint::url('it is not an absolute URL');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && (!$allowHttp || $scheme !== 'http')) {
            throw InvalidEndpoint::url($allowHttp ? 'only http and https are delivered to' : 'deliveries go over https only');
        }

        // Credentials in a URL end up in logs, screens and error messages.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw InvalidEndpoint::url('it must not carry credentials; put them in a secret instead');
        }

        if (isset($parts['fragment'])) {
            throw InvalidEndpoint::url('a fragment is never sent to a server');
        }

        return new self($url, $scheme, strtolower(trim($parts['host'], '[]')), $parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }
}
