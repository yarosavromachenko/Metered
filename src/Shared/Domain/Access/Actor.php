<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Access;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Who asked for the change: a user (authorised through their membership) or
 * the system (console, scheduler, provisioning), which is not checked. The
 * label goes into the audit log.
 */
final readonly class Actor
{
    private function __construct(
        public ?Uuid $userId,
        public string $label,
    ) {}

    public static function user(Uuid $userId, string $email): self
    {
        return new self($userId, 'user:' . strtolower(trim($email)));
    }

    /**
     * @param  string  $label  e.g. `console:org:create`
     */
    public static function system(string $label): self
    {
        return new self(null, $label);
    }

    public function isSystem(): bool
    {
        return !$this->userId instanceof Uuid;
    }
}
