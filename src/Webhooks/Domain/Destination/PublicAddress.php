<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Destination;

/**
 * SSRF check. IPv4: private, loopback, link-local (cloud metadata), CGNAT,
 * multicast, documentation and reserved ranges are refused. IPv6: only global
 * unicast, minus IPv4 tunnelling blocks; forms embedding an IPv4 address are
 * judged by that address.
 */
final class PublicAddress
{
    public static function allows(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return ! self::inAny($packed, self::blockedV4());
        }

        if (self::inAny($packed, self::embeddingV4())) {
            return ! self::inAny(substr($packed, 12), self::blockedV4());
        }

        return self::inAny($packed, self::globalV6()) && ! self::inAny($packed, self::blockedV6());
    }

    /**
     * @param list<string> $ranges
     */
    private static function inAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $prefix = (string) inet_pton($network);

            if (strlen($prefix) === strlen($packed) && self::samePrefix($packed, $prefix, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function samePrefix(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if (strncmp($a, $b, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /**
     * @return list<string>
     */
    private static function blockedV4(): array
    {
        return self::ranges(<<<'RANGES'
            0.0.0.0/8         this network
            10.0.0.0/8        private
            100.64.0.0/10     carrier-grade NAT
            127.0.0.0/8       loopback
            169.254.0.0/16    link-local, where cloud metadata answers
            172.16.0.0/12     private
            192.0.0.0/24      IETF protocol assignments
            192.0.2.0/24      documentation
            192.88.99.0/24    6to4 relay anycast
            192.168.0.0/16    private
            198.18.0.0/15     benchmarking
            198.51.100.0/24   documentation
            203.0.113.0/24    documentation
            224.0.0.0/4       multicast
            240.0.0.0/4       reserved, and broadcast
            RANGES);
    }

    /**
     * Allow-list: anything outside is refused.
     *
     * @return list<string>
     */
    private static function globalV6(): array
    {
        return self::ranges(<<<'RANGES'
            2000::/3          global unicast
            RANGES);
    }

    /**
     * Transition blocks inside global unicast that carry an IPv4 address.
     *
     * @return list<string>
     */
    private static function blockedV6(): array
    {
        return self::ranges(<<<'RANGES'
            2001::/23         IETF protocol assignments, Teredo among them
            2001:db8::/32     documentation
            2002::/16         6to4
            3fff::/20         documentation
            RANGES);
    }

    /**
     * @return list<string>
     */
    private static function embeddingV4(): array
    {
        return self::ranges(<<<'RANGES'
            ::ffff:0:0/96     IPv4-mapped
            64:ff9b::/96      NAT64
            RANGES);
    }

    /**
     * First word of each line; parsed at runtime so mutation testing covers it
     * (docs/testing.md).
     *
     * @return list<string>
     */
    private static function ranges(string $table): array
    {
        return array_map(static fn(string $line): string => (string) strtok(trim($line), ' '), explode("\n", $table));
    }
}
