<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use LogicException;
use Metered\Tenancy\Domain\Exception\MalformedApiKey;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The credential a client sends, in the one place it is allowed to exist.
 *
 * Format: `mk_<environment>_<prefix>_<secret>`. The prefix is stored in the
 * clear and is what a lookup finds; the secret is stored only as a SHA-256
 * hash, and the plaintext exists exactly once — in the response that created
 * the key (ADR-0017).
 *
 * A fast hash is right here, unlike for a password. The secret is 192 bits of
 * randomness rather than something a human chose, so there is no dictionary to
 * try and nothing for a slow hash to defend; what a slow hash would buy instead
 * is a KDF on the hot path of every authenticated request.
 *
 * The plaintext is wrapped in SensitiveParameterValue, so print_r, var_export,
 * var_dump and json_encode cannot reach it and serialize() refuses outright —
 * a secret that reaches a queue payload, a session or a cache entry has been
 * stored, which is precisely what must never happen. Reading it back takes
 * reveal(), a name chosen to stand out in review.
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
            // The whole token is hashed, not the secret alone, so a hash is
            // bound to the environment and prefix it was issued with.
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

        // The wrapper hands back mixed; it was constructed from the string
        // this class validated, so anything else is a broken invariant rather
        // than an empty token to hand out.
        return is_string($token)
            ? $token
            : throw new LogicException('An API key secret lost its token.');
    }
}
