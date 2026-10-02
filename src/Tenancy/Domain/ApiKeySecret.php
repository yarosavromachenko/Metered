<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use LogicException;
use Metered\Tenancy\Domain\Exception\MalformedApiKey;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Token format `mk_<environment>_<prefix>_<secret>`. The prefix is stored in
 * clear for lookup, the token as SHA-256; the plaintext is returned once, on
 * creation (ADR-0017). SHA-256 is enough because the secret is 192 random
 * bits, and a slow hash would run on every request.
 *
 * Held in SensitiveParameterValue: hidden from dumps and json_encode, and
 * serialize() throws. Read it with reveal().
 */
final readonly class ApiKeySecret
{
    private const string PATTERN = '/^mk_(live|test)_([0-9a-f]{8})_[0-9a-f]{48}$/';

    private function __construct(
        private Environment $environment,
        private string $prefix,
        private string $hash,
        private SensitiveParameterValue $token,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => sprintf('mk_%s_%s_<redacted>', $this->environment->value, $this->prefix)];
    }

    public static function generate(Environment $environment): self
    {
        return self::fromToken(sprintf(
            'mk_%s_%s_%s',
            $environment->value,
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(24)),
        ));
    }

    public static function fromToken(#[SensitiveParameter] string $token): self
    {
        if (preg_match(self::PATTERN, $token, $matches) !== 1) {
            throw MalformedApiKey::badShape();
        }

        return new self(
            Environment::from($matches[1]),
            $matches[2],
            // The whole token, so the hash is bound to environment and prefix.
            hash('sha256', $token),
            new SensitiveParameterValue($token),
        );
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function reveal(): string
    {
        $token = $this->token->getValue();

        // The wrapper returns mixed; anything but a string is a bug.
        return is_string($token)
            ? $token
            : throw new LogicException('An API key secret lost its token.');
    }
}
