<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Identity;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use SensitiveParameter;

/**
 * Sign-in accounts. The framework owns them (a row, a password hash, a
 * session); the domain models Membership. register() takes the plaintext
 * password, and the implementation hashes it.
 */
interface UserAccounts
{
    public function register(
        string $name,
        string $email,
        #[SensitiveParameter]
        string $plainPassword,
        DateTimeImmutable $at,
    ): Uuid;

    public function existsWithEmail(string $email): bool;

    public function recordSignIn(Uuid $userId, DateTimeImmutable $at): void;

    /**
     * Deletes the given accounts that no longer belong to any organization.
     *
     * @param  list<Uuid>  $userIds
     */
    public function removeUnaffiliated(array $userIds): void;
}
