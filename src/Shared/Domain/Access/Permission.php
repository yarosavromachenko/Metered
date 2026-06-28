<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Access;

/**
 * The kinds of authority the panel distinguishes.
 *
 * Five, not a per-resource matrix. Each one answers a question a real billing
 * organization asks about a colleague: may they look, may they shape what is
 * sold, may they operate the plumbing, may they decide what a customer is
 * charged, may they change who has access.
 *
 * The authority is kernel vocabulary; which role carries it is Tenancy's
 * answer ({@see \Metered\Tenancy\Domain\Role}). Every module's handlers name
 * a permission, so the enum they name cannot be private to one of them.
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
