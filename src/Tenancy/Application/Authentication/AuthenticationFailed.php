<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Authentication;

use RuntimeException;

/**
 * Why a request could not be authenticated.
 *
 * Three reasons, and the client is told which. A caller holding the token is
 * not learning anything it does not already have, and "your key was revoked"
 * saves an afternoon that "unauthorized" would have cost.
 *
 * What is deliberately not distinguished: an unknown prefix and a wrong secret
 * are both `invalid-api-key`. Telling those apart would turn the endpoint into
 * an oracle for which prefixes exist.
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
