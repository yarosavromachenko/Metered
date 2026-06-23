<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Identity\UserAccounts;
use Metered\Tenancy\Domain\Membership;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\Role;
use Psr\Clock\ClockInterface;

/**
 * Turns a sign-up form into a person who owns a tenant.
 *
 * The same provisioning path an operator takes with `org:create`, plus the
 * account and the membership that make it somebody's. Using one path for both
 * is deliberate: the demo then exercises what a real first user does, rather
 * than a shortcut written for the demo.
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
        private MembershipRepository $memberships,
        private Transactions $transactions,
        private IdentifierGenerator $ids,
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
            $actor = 'user:' . strtolower(trim($command->email));

            $userId = $this->users->register($command->name, $command->email, $command->password, $now);

            $tenant = $this->provisionTenant->handle(new ProvisionTenant(
                organizationName: $command->organizationName,
                actor: $actor,
            ));

            // Owner of the organization they just created — the same role the
            // first person in any organization has.
            $this->memberships->save(new Membership(
                $this->ids->generate(),
                $tenant->organization->id,
                $userId,
                Role::Owner,
                $now,
            ));

            $this->audit->record(new AuditEntry(
                actor: $actor,
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
