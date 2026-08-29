<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Text\Name;

/**
 * A tenant of the platform: the company that bills its own customers.
 *
 * An organization owns projects, members and invoice numbering, and nothing
 * else hangs off it directly — meters, plans and customers belong to a
 * project. That indirection is what lets a tenant keep a `test` environment
 * whose data can be wiped without touching anything a customer was charged
 * for.
 *
 * `demo` is decided when the organization is created and never afterwards: a
 * demo tenant is deleted, money history and all, a week after its people stop
 * coming back (ADR-0016), and nothing else ever may be. The database holds the
 * flag fixed, because the invoicing triggers trust it.
 */
final readonly class Organization
{
    public const int NAME_LIMIT = 120;

    private function __construct(
        public Uuid $id,
        public string $name,
        public Slug $slug,
        public DateTimeImmutable $createdAt,
        public bool $demo,
    ) {}

    public static function register(
        Uuid $id,
        string $name,
        Slug $slug,
        DateTimeImmutable $at,
        bool $demo = false,
    ): self {
        return new self($id, Name::of($name, 'organization', self::NAME_LIMIT), $slug, $at, $demo);
    }
}
