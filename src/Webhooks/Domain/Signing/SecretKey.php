<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Signing;

use Metered\Webhooks\Domain\Exception\InvalidEndpoint;

/**
 * Not Stringable, so it cannot end up in a log line by interpolation.
 */
final readonly class SecretKey
{
    private const string PATTERN = '/^whsec_[A-Za-z0-9_-]{32,}$/';

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidEndpoint::secret();
        }

        return new self($value);
    }

    /**
     * From 32 random bytes supplied by the caller.
     */
    public static function fromBytes(string $bytes): self
    {
        return self::fromString('whsec_' . rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='));
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function masked(): string
    {
        return 'whsec_…' . substr($this->value, -4);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
