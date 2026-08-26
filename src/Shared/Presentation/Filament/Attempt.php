<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Filament;

use Closure;
use Filament\Notifications\Notification;
use Metered\Shared\Application\Exception\Conflict;
use Metered\Shared\Application\Exception\NotFound;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Exception\DomainException;
use Metered\Shared\Domain\Tenant\TenantContext;
use RuntimeException;

/**
 * Runs one handler from a panel action and says how it went.
 *
 * A refusal — a broken rule, a missing permission, something that is not
 * there — becomes a red notification in the handler's own words; anything
 * else is a bug and is left to fail loudly. Without a tenant in scope nothing
 * runs at all. The caller passes the tenant in: the kernel does not know
 * where the panel keeps it.
 */
final class Attempt
{
    /**
     * @param Closure(TenantContext): string $change returns the success message
     */
    public static function change(?TenantContext $tenant, Closure $change): null
    {
        if (! $tenant instanceof TenantContext) {
            return null;
        }

        try {
            $message = $change($tenant);
        } catch (PermissionDenied|DomainException|NotFound|Conflict $refused) {
            Notification::make()
                ->title($refused instanceof RuntimeException ? $refused->getMessage() : 'That change was refused.')
                ->danger()
                ->send();

            return null;
        }

        Notification::make()->title($message)->success()->send();

        return null;
    }
}
