<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Actions;

use Closure;
use Filament\Notifications\Notification;
use Metered\Shared\Application\Exception\Conflict;
use Metered\Shared\Application\Exception\NotFound;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Exception\DomainException;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope;
use RuntimeException;

/**
 * Runs one handler for the panel's current tenant and reports how it went.
 *
 * A refusal — a rule, a permission, a missing invoice — becomes a red
 * notification with the handler's own words; anything else is a bug and is
 * left to fail loudly.
 */
final class Attempt
{
    /**
     * @param Closure(TenantContext): string $change returns the success message
     */
    public static function change(Closure $change): null
    {
        $tenant = app(PanelScope::class)->tenant();

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
