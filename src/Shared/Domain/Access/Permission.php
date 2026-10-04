<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Access;

/**
 * Which role grants which permission is decided in
 * {@see \Metered\Tenancy\Domain\Role}.
 */
enum Permission: string
{
    /** Read anything inside the organization. */
    case ViewOrganization = 'organization.view';

    /** Meters, plans, versions, prices, customers, subscriptions. */
    case ManageCatalog = 'catalog.manage';

    /** Webhook endpoints: rotate a secret, replay a delivery. */
    case OperateWebhooks = 'webhooks.operate';

    /** Finalize, void, pay, credit — everything that moves money. */
    case MoveMoney = 'money.move';

    /** Members, projects, API keys, and the organization itself. */
    case ManageTenant = 'tenant.manage';
}
