<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Resources\ApiKeys\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Metered\Tenancy\Application\Authorization\PermissionDenied;
use Metered\Tenancy\Application\Command\IssueApiKey;
use Metered\Tenancy\Application\Command\IssueApiKeyHandler;
use Metered\Tenancy\Domain\Permission;
use Metered\Tenancy\Domain\Scope;
use Metered\Tenancy\Presentation\Filament\PanelActor;
use Metered\Tenancy\Presentation\Filament\PanelScope;
use Metered\Tenancy\Presentation\Filament\Resources\ApiKeys\ApiKeyResource;

/**
 * Issuing a key, through the handler the API uses.
 *
 * The secret is shown once, in a notification that does not close by itself,
 * and it exists nowhere else — not in the row, not in the session, not in this
 * page's state after the request ends.
 *
 * The action is hidden from anyone who may not use it and refused again inside
 * the handler. The second check is the real one; the first is good manners.
 */
final class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue key')
                ->icon('heroicon-o-plus')
                ->visible(fn(): bool => app(PanelScope::class)->may(Permission::ManageTenant))
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
                ->action(function (array $data): void {
                    $tenant = app(PanelScope::class)->tenant();

                    if ($tenant === null) {
                        return;
                    }

                    $scopes = self::scopesIn($data['scopes'] ?? null);

                    try {
                        $issued = app(IssueApiKeyHandler::class)->handle(new IssueApiKey(
                            $tenant,
                            is_string($data['name'] ?? null) ? trim($data['name']) : 'API key',
                            $scopes,
                            PanelActor::current(),
                        ));
                    } catch (PermissionDenied $denied) {
                        Notification::make()->title($denied->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Key issued — copy it now')
                        ->body($issued->secret->reveal())
                        ->persistent()
                        ->success()
                        ->send();
                }),
        ];
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
}
