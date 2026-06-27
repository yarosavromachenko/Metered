<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Authorization;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * Who asked for something to happen.
 *
 * Two kinds. A person, identified by their user id, whose authority is the
 * membership connecting them to the organization. And the system — a console
 * command, a scheduled job, the provisioning that creates an organization
 * before anyone is a member of it — which has no membership to check and is
 * trusted by virtue of running at all.
 *
 * Both carry a label, because both end up in the audit log, and "who did
 * this?" is the first question asked of it.
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
     * A caller with no person behind it. The label says which one, so an
     * audit entry names `console:org:create` rather than merely "system".
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
