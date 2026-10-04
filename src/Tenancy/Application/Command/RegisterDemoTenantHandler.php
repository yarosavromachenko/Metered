<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Tenancy\Application\Identity\UserAccounts;
use Metered\Tenancy\Domain\Role;
use Psr\Clock\ClockInterface;

/**
 * Creates the account, then provisions the tenant the same way `org:create`
 * does, with that account as owner. Whether sign-up is open is checked by the
 * page, not here.
 */
final readonly class RegisterDemoTenantHandler
{
    public function __construct(
        private UserAccounts $users,
        private ProvisionTenantHandler $provisionTenant,
        private Transactions $transactions,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(RegisterDemoTenant $command): RegisteredDemoTenant
    {
        return $this->transactions->run(function () use ($command): RegisteredDemoTenant {
            if ($this->users->existsWithEmail($command->email)) {
                // The unique index enforces it; this gives the form a message.
                throw EmailAlreadyRegistered::withEmail($command->email);
            }

            $now = $this->clock->now();
            $userId = $this->users->register($command->name, $command->email, $command->plainPassword, $now);
            $actor = Actor::user($userId, $command->email);

            $tenant = $this->provisionTenant->handle(new ProvisionTenant(
                organizationName: $command->organizationName,
                actor: $actor,
                ownerUserId: $userId,
                demo: true,
            ));

            $this->audit->record(new AuditEntry(
                organizationId: $tenant->organization->id,
                actor: $actor->label,
                action: 'user.registered',
                subjectType: 'user',
                subjectId: $userId->value,
                payload: [
                    'organization_id' => $tenant->organization->id->value,
                    'role' => Role::Owner->value,
                    'demo' => true,
                ],
                occurredAt: $now,
            ));

            return new RegisteredDemoTenant($userId, $tenant);
        });
    }
}
