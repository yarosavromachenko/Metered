<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Metered\Billing\Application\Command\DefineMeter;
use Metered\Billing\Application\Command\DefineMeterHandler;
use Metered\Billing\Application\Command\MeterCodeTaken;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Exception\DomainException;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Tenancy\Application\Contract\PanelScope;

/**
 * Defining a meter from the panel, through the handler (ADR-0015).
 *
 * A class rather than a closure, so that the body can be called by a test
 * without a browser. The panel's first closure action had a bug in its
 * opening line that no rendering test caught.
 */
final class DefineMeterAction
{
    public static function make(): Action
    {
        return Action::make('define')
            ->label('New meter')
            ->icon('heroicon-o-plus')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::ManageCatalog))
            ->schema([
                TextInput::make('code')
                    ->required()
                    ->maxLength(64)
                    ->helperText('What events call this meter. Lowercased, and fixed once usage arrives.'),
                TextInput::make('name')->required()->maxLength(120),
                Select::make('aggregation')
                    ->options(self::aggregations())
                    ->default(Aggregation::Sum->value)
                    ->required()
                    ->helperText('How its events become one number. It cannot be changed later.'),
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

        $aggregation = is_string($data['aggregation'] ?? null)
            ? Aggregation::tryFrom($data['aggregation'])
            : null;

        try {
            $meter = app(DefineMeterHandler::class)->handle(new DefineMeter(
                tenant: $tenant,
                code: self::text($data['code'] ?? null),
                name: self::text($data['name'] ?? null),
                aggregation: $aggregation ?? Aggregation::Sum,
                actor: app(PanelScope::class)->actor(),
            ));
        } catch (MeterCodeTaken|PermissionDenied|DomainException $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();

            return null;
        }

        Notification::make()
            ->title(sprintf('Meter "%s" defined.', $meter->code->value))
            ->body('Events naming this code will now be counted.')
            ->success()
            ->send();

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function aggregations(): array
    {
        $options = [];

        foreach (Aggregation::cases() as $aggregation) {
            $options[$aggregation->value] = $aggregation->label();
        }

        return $options;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
