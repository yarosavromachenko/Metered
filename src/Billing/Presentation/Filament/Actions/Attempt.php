<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

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
 * Runs one catalog change from the panel and tells the user how it went.
 *
 * Every refusal the handlers can give — a missing permission, a broken rule,
 * something not in this project, a code already taken — becomes a red
 * notification carrying the handler's own message, which is written for the
 * person who will read it. Anything else is a bug and is left to surface.
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
