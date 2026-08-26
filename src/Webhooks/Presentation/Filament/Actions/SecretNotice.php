<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Actions;

use Filament\Notifications\Notification;
use Metered\Webhooks\Domain\Signing\SecretKey;

/**
 * Shows a new signing secret, the one time it is shown. It stays on screen
 * until dismissed: a secret that vanished after five seconds would have to
 * be rotated again.
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
