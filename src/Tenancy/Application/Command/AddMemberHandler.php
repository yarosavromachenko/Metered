<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Identity\UserAccounts;
use Metered\Tenancy\Domain\Membership;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\Organization;
use Metered\Tenancy\Domain\OrganizationRepository;
use Psr\Clock\ClockInterface;

final readonly class AddMemberHandler
{
    public function __construct(
        private OrganizationRepository $organizations,
        private MembershipRepository $memberships,
        private UserAccounts $users,
        private Transactions $transactions,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(AddMember $command): Uuid
    {
        return $this->transactions->run(function () use ($command): Uuid {
            $organization = $this->organizations->findBySlug($command->organization);

            if (! $organization instanceof Organization) {
                throw TenantNotFound::organizationNamed($command->organization);
            }

            if ($this->users->existsWithEmail($command->email)) {
                throw EmailAlreadyRegistered::withEmail($command->email);
            }

            $now = $this->clock->now();
            $userId = $this->users->register($command->name, $command->email, $command->password, $now);
            $this->memberships->save(new Membership($this->ids->generate(), $organization->id, $userId, $command->role, $now));

            $this->audit->record(new AuditEntry(
                actor: $command->actor->label,
                action: 'member.added',
                subjectType: 'organization',
                subjectId: $organization->id->value,
                payload: ['user_id' => $userId->value, 'role' => $command->role->value],
                occurredAt: $now,
            ));

            return $userId;
        });
    }
}
