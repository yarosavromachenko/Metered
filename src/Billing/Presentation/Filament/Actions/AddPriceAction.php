<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Brick\Math\Exception\MathException;
use Brick\Money\Money as BrickMoney;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Metered\Billing\Application\Command\AddPrice;
use Metered\Billing\Application\Command\AddPriceHandler;
use Metered\Billing\Domain\Exception\InvalidPricing;
use Metered\Billing\Domain\MeterRepository;
use Metered\Billing\Infrastructure\Eloquent\PlanVersion;
use Metered\Billing\Presentation\Http\MeterCodes;
use Metered\Billing\Presentation\Http\PriceInput;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;

/**
 * Adds a price to a draft version. The form asks a person for amounts the way
 * they read them — "49.00", not 4900 — and the rest is the same parsing the
 * API uses, so the panel cannot build a price the API would refuse.
 */
final class AddPriceAction
{
    public static function make(): Action
    {
        return Action::make('addPrice')
            ->label('Add price')
            ->icon('heroicon-o-plus-circle')
            ->visible(static fn(PlanVersion $record): bool => $record->published_at === null
                && app(PanelScope::class)->may(Permission::ManageCatalog))
            ->schema([
                Select::make('model')
                    ->options([
                        'flat_fee' => 'Flat fee per period',
                        'per_unit' => 'Per unit',
                        'graduated' => 'Graduated tiers',
                        'volume' => 'Volume tiers',
                    ])
                    ->default('flat_fee')
                    ->required()
                    ->live(),
                Select::make('meter')
                    ->options(static fn(): array => self::meters())
                    ->required()
                    ->visible(static fn(Get $get): bool => $get('model') !== 'flat_fee'),
                TextInput::make('amount')
                    ->label('Amount per period')
                    ->required()
                    ->visible(static fn(Get $get): bool => $get('model') === 'flat_fee')
                    ->helperText('In the project\'s currency, such as 49.00.'),
                TextInput::make('unit_price')
                    ->required()
                    ->visible(static fn(Get $get): bool => $get('model') === 'per_unit')
                    ->helperText('Up to eight decimal places, such as 0.00012.'),
                Repeater::make('tiers')
                    ->schema([
                        TextInput::make('up_to')->label('Up to (inclusive)')->placeholder('leave empty on the last tier'),
                        TextInput::make('unit_price')->required(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->visible(static fn(Get $get): bool => in_array($get('model'), ['graduated', 'volume'], true)),
            ])
            ->action(static fn(PlanVersion $record, array $data): null => self::run($record->id, $record->currency, $data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(string $versionId, string $currency, array $data): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($versionId, $currency, $data): string {
            $model = Form::text($data['model'] ?? null);

            app(AddPriceHandler::class)->handle(new AddPrice(
                $tenant,
                Uuid::fromString($versionId),
                PriceInput::model([
                    'model' => $model,
                    'amount' => $model === 'flat_fee' ? self::minorUnits(Form::text($data['amount'] ?? null), $currency) : null,
                    'unit_price' => Form::text($data['unit_price'] ?? null),
                    'tiers' => self::tiers($data['tiers'] ?? null),
                ], $currency),
                $model === 'flat_fee' ? null : Form::uuid($data['meter'] ?? null),
                app(PanelScope::class)->actor(),
            ));

            return 'Price added.';
        });
    }

    /**
     * @return array<string, string>
     */
    private static function meters(): array
    {
        $tenant = app(PanelScope::class)->tenant();

        return $tenant instanceof TenantContext ? MeterCodes::of(app(MeterRepository::class)->listFor($tenant)) : [];
    }

    /**
     * "49.00" in EUR is 4900. More places than the currency has is refused
     * rather than rounded: the form must not charge something else than was
     * typed.
     */
    private static function minorUnits(string $amount, string $currency): int
    {
        try {
            return BrickMoney::of($amount, $currency)->getMinorAmount()->toInt();
        } catch (MathException) {
            throw InvalidPricing::unreadableAmount($amount, $currency);
        }
    }

    /**
     * @return list<array{up_to: ?string, unit_price: string}>
     */
    private static function tiers(mixed $rows): array
    {
        $tiers = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $row = is_array($row) ? $row : [];
            $limit = Form::text($row['up_to'] ?? null);
            $tiers[] = ['up_to' => $limit === '' ? null : $limit, 'unit_price' => Form::text($row['unit_price'] ?? null)];
        }

        return $tiers;
    }
}
