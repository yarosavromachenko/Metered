<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\CustomerReference;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

final readonly class RegisterCustomerHandler
{
    public function __construct(
        private CustomerRepository $customers,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(RegisterCustomer $command): Customer
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        $reference = CustomerReference::fromString($command->reference);

        if ($this->customers->findByReference($command->tenant, $reference) instanceof Customer) {
            throw CustomerReferenceTaken::withReference($reference);
        }

        $customer = Customer::register(
            $this->ids->generate(),
            $command->tenant,
            $reference,
            $command->name,
            $this->clock->now(),
        );

        $this->customers->save($customer);

        $this->audit->record(new AuditEntry(
            organizationId: $command->tenant->organizationId,
            actor: $command->actor->label,
            action: 'customer.registered',
            subjectType: 'customer',
            subjectId: $customer->id->value,
            payload: [
                'project_id' => $customer->tenant->projectId->value,
                'reference' => $customer->reference->value,
            ],
            occurredAt: $customer->registeredAt,
        ));

        return $customer;
    }
}
