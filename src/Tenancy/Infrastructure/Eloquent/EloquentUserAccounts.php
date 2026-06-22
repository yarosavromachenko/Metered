<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Identity\UserAccounts;
use SensitiveParameter;

/**
 * Accounts on top of the users table and the framework's hasher.
 *
 * The password is hashed here and nowhere else: the plaintext arrives as an
 * argument, becomes a hash, and ends with this method. It is never logged,
 * never returned and stored in no other form.
 *
 * Rows rather than Eloquent, like the other repositories in this module. The
 * User model exists because authentication and Filament need an
 * Authenticatable; writing through it as well would put the same mapping in
 * two places.
 */
final readonly class EloquentUserAccounts implements UserAccounts
{
    public function __construct(
        private DatabaseManager $db,
        private Hasher $hasher,
        private IdentifierGenerator $ids,
    ) {}

    public function register(
        string $name,
        string $email,
        #[SensitiveParameter]
        string $password,
        DateTimeImmutable $at,
    ): Uuid {
        $id = $this->ids->generate();

        $this->db->connection()->table('users')->insert([
            'id' => $id->value,
            'name' => $name,
            'email' => self::normalise($email),
            'password' => $this->hasher->make($password),
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $id;
    }

    public function existsWithEmail(string $email): bool
    {
        return $this->db->connection()->table('users')
            ->where('email', self::normalise($email))
            ->exists();
    }

    public function recordSignIn(Uuid $userId, DateTimeImmutable $at): void
    {
        $this->db->connection()->table('users')
            ->where('id', $userId->value)
            ->update(['last_signed_in_at' => $at]);
    }

    private static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }
}
