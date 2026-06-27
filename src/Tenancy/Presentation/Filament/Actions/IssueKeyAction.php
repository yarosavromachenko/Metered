<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Metered\Tenancy\Application\Authorization\PermissionDenied;
use Metered\Tenancy\Application\Command\IssueApiKey;
use Metered\Tenancy\Application\Command\IssueApiKeyHandler;
use Metered\Tenancy\Domain\Permission;
use Metered\Tenancy\Domain\Scope;
use Metered\Tenancy\Presentation\Filament\PanelActor;
use Metered\Tenancy\Presentation\Filament\PanelScope;

/**
 * Issuing a key from the panel, through the handler the API uses.
 *
 * The work lives in a method rather than inside the action's closure, so it
 * can be called by a test without driving a Livewire component. A closure that
 * only runs in a browser is a closure nothing checks — and the first version of
 * this one had a bug in its very first line.
 *
 * The secret is shown once, in a notification that does not close by itself,
 * and it exists nowhere else: not in the row, not in the session, not in the
 * page's state once the request ends.
 */
final class IssueKeyAction
{
    public static function make(): Action
    {
        return Action::make('issue')
            ->label('Issue key')
            ->icon('heroicon-o-plus')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::ManageTenant))
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(80)
                    ->helperText('Where this key will be used — "CI ingestion", "billing service".'),
                CheckboxList::make('scopes')
                    ->options([
                        Scope::UsageWrite->value => 'usage:write — report usage events',
                        Scope::Admin->value => 'admin — manage the catalog over the API',
                    ])
                    ->default([Scope::UsageWrite->value])
                    ->required(),
            ])
            ->action(static fn(array $data): null => self::run($data));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function run(array $data): null
    {
        $tenant = app(PanelScope::class)->tenant();

        if ($tenant === null) {
            return null;
        }

        try {
            $issued = app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
                $tenant,
                self::text($data['name'] ?? null, 'API key'),
                self::scopesIn($data['scopes'] ?? null),
                PanelActor::current(),
            ));
        } catch (PermissionDenied $denied) {
            Notification::make()->title($denied->getMessage())->danger()->send();

            return null;
        }

        Notification::make()
            ->title('Key issued — copy it now')
            ->body($issued->secret->reveal())
            ->persistent()
            ->success()
            ->send();

        return null;
    }

    /**
     * The scopes a checkbox list produced, as the domain's own type. Anything
     * else in that array is dropped rather than trusted.
     *
     * @return list<Scope>
     */
    private static function scopesIn(mixed $value): array
    {
        $scopes = [];

        foreach (is_array($value) ? $value : [] as $scope) {
            $parsed = is_string($scope) ? Scope::tryFrom($scope) : null;

            if ($parsed instanceof Scope) {
                $scopes[] = $parsed;
            }
        }

        return $scopes;
    }

    private static function text(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
