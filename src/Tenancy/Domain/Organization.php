<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Text\Name;

/**
 * A tenant. Owns projects, members and invoice numbering; everything else
 * belongs to a project. `demo` is set at creation and cannot change (the
 * database enforces it): only demo organizations may be purged (ADR-0016).
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
