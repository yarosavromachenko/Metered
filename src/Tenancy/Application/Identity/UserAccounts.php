<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Identity;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use SensitiveParameter;

/**
 * The people who can sign in, as far as the application layer is concerned.
 *
 * Not a repository over a domain entity, on purpose: a user here is an
 * identity the framework owns — a row, a password hash, a session. What the
 * domain models is Membership, which is what decides anything. Keeping the
 * account behind this port means password hashing stays in infrastructure,
 * where the library that does it lives.
 */
interface UserAccounts
{
    public function register(
        string $name,
        string $email,
        #[SensitiveParameter]
        string $password,
        DateTimeImmutable $at,
    ): Uuid;

    public function existsWithEmail(string $email): bool;

    public function recordSignIn(Uuid $userId, DateTimeImmutable $at): void;

    /**
     * Deletes those of these accounts that belong to no organization any
     * more. Somebody who is also a member elsewhere keeps their account.
     *
     * @param  list<Uuid>  $userIds
     */
    public function removeUnaffiliated(array $userIds): void;
}
