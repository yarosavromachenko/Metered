<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Signing;

use Metered\Webhooks\Domain\Exception\InvalidEndpoint;

/**
 * The secret an endpoint's deliveries are signed with.
 *
 * Not Stringable on purpose: a secret interpolated into a log line or an
 * exception message is a secret leaked. It is revealed by asking for it, and
 * shown to a person once, when it is created.
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
     * A new secret from 32 random bytes the caller drew. The domain does not
     * draw them itself: where randomness comes from is not its business.
     */
    public static function fromBytes(string $bytes): self
    {
        return self::fromString('whsec_' . rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='));
    }

    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * Enough to tell two secrets apart on a screen, and nothing more.
     */
    public function masked(): string
    {
        return 'whsec_…' . substr($this->value, -4);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
