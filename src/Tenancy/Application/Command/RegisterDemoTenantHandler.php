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
 * Turns a sign-up form into a person who owns a tenant.
 *
 * The same provisioning path an operator takes with `org:create`, with the
 * account created first and handed in as the owner. Using one path for both is
 * deliberate: the demo then exercises what a real first user does, rather than
 * a shortcut written for the demo.
 *
 * Whether sign-up is open at all is not decided here — that is demo mode, and
 * the page that offers the form is what consults it. This handler's job is
 * that the result is a complete, usable tenant or nothing.
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
                // The unique index would refuse it anyway; this turns a
                // constraint violation into a sentence the form can show.
                throw EmailAlreadyRegistered::withEmail($command->email);
            }

            $now = $this->clock->now();
            $userId = $this->users->register($command->name, $command->email, $command->password, $now);
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
