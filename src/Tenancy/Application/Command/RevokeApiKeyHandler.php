<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Tenancy\Application\Authorization\PermissionGuard;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Psr\Clock\ClockInterface;

/**
 * Tenant-scoped lookup: another organization's key is not found.
 */
final readonly class RevokeApiKeyHandler
{
    public function __construct(
        private ApiKeyRepository $keys,
        private PermissionGuard $guard,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(RevokeApiKey $command): ApiKey
    {
        $key = $this->keys->find($command->tenant, $command->keyId);

        if (! $key instanceof ApiKey) {
            throw TenantNotFound::apiKey($command->keyId);
        }

        $this->guard->ensure($command->actor, $key->tenant->organizationId, Permission::ManageTenant);

        $revokedAt = $this->clock->now();
        $revoked = $key->revoke($revokedAt);

        $this->keys->save($revoked);

        $this->audit->record(new AuditEntry(
            organizationId: $key->tenant->organizationId,
            actor: $command->actor->label,
            action: 'api_key.revoked',
            subjectType: 'api_key',
            subjectId: $key->id->value,
            payload: [
                'project_id' => $key->tenant->projectId->value,
                'prefix' => $key->prefix,
                'revoked_at' => $revoked->revokedAt?->format(DATE_RFC3339),
            ],
            occurredAt: $revokedAt,
        ));

        return $revoked;
    }
}
