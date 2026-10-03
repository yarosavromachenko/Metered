<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Actions;

use Filament\Notifications\Notification;
use Metered\Webhooks\Domain\Signing\SecretKey;

/**
 * Persistent notification with the new secret.
 */
final class SecretNotice
{
    public static function show(SecretKey $secret): void
    {
        Notification::make()
            ->title('Signing secret — copy it now, it will not be shown again')
            ->body($secret->reveal())
            ->warning()
            ->persistent()
            ->send();
    }
}
