<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Authentication;

use RuntimeException;

/**
 * The reason is returned to the client. An unknown prefix and a wrong secret
 * are both `invalid-api-key`, so the endpoint does not reveal which prefixes
 * exist.
 */
final class AuthenticationFailed extends RuntimeException
{
    private function __construct(
        public readonly string $problem,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function malformed(): self
    {
        return new self(
            'invalid-api-key',
            'The Authorization header does not carry an API key in the expected format.',
        );
    }

    public static function unknownKey(): self
    {
        return new self('invalid-api-key', 'This API key is not valid.');
    }

    public static function revoked(): self
    {
        return new self('revoked-api-key', 'This API key was revoked.');
    }
}
