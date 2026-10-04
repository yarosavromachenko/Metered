<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Tenancy\Application\Command\IssueApiKey;
use Metered\Tenancy\Application\Command\IssueApiKeyHandler;
use Metered\Tenancy\Domain\Scope;
use Metered\Tenancy\Presentation\Filament\PanelActor;
use Metered\Tenancy\Presentation\Filament\PanelScope;

/**
 * The work is in a method, not the closure, so tests can call it directly.
 * The secret is shown once in a persistent notification and stored nowhere.
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
     * Unknown values are dropped.
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
