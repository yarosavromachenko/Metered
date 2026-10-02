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
 * Runs a handler from a panel action. Domain errors, permission denials and
 * not-found become a danger notification with the exception message; other
 * exceptions propagate. Nothing runs without a tenant.
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
