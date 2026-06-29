<?php

declare(strict_types=1);

use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\CustomerReference;
use Metered\Shared\Domain\Exception\InvalidName;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

const CUSTOMER_ID = '01924b7c-0000-7000-8000-0000000000e1';
const CUSTOMER_ORG_ID = '01924b7c-0000-7000-8000-0000000000e2';
const CUSTOMER_PROJECT_ID = '01924b7c-0000-7000-8000-0000000000e3';
const REGISTERED_AT = '2026-09-22T09:30:00+00:00';

it('is the tenant’s customer, carried under the tenant’s own identifier', function (): void {
    $customer = registerCustomer('cus_4471', 'North Wind Ltd');

    expect($customer->id->value)->toBe(CUSTOMER_ID)
        ->and($customer->tenant->projectId->value)->toBe(CUSTOMER_PROJECT_ID)
        ->and((string) $customer->reference)->toBe('cus_4471')
        ->and($customer->name)->toBe('North Wind Ltd')
        ->and($customer->registeredAt->format(DATE_ATOM))->toBe(REGISTERED_AT);
});

it('refuses a name that would not fit the column', function (): void {
    expect(static fn(): Customer => registerCustomer('cus_4471', str_repeat('n', 121)))
        ->toThrow(InvalidName::class, 'at most 120');
});

it('answers to the reference the events carry', function (): void {
    $customer = registerCustomer('cus_4471', 'North Wind Ltd');

    expect($customer->answersTo(CustomerReference::fromString('cus_4471')))->toBeTrue()
        ->and($customer->answersTo(CustomerReference::fromString('cus_4472')))->toBeFalse();
});

function registerCustomer(string $reference, string $name): Customer
{
    return Customer::register(
        Uuid::fromString(CUSTOMER_ID),
        new TenantContext(Uuid::fromString(CUSTOMER_ORG_ID), Uuid::fromString(CUSTOMER_PROJECT_ID)),
        CustomerReference::fromString($reference),
        $name,
        new DateTimeImmutable(REGISTERED_AT),
    );
}
