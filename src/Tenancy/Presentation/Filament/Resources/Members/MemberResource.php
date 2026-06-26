<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Resources\Members;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Infrastructure\Eloquent\OrganizationMember;
use Metered\Tenancy\Presentation\Filament\PanelScope;
use Metered\Tenancy\Presentation\Filament\Resources\Members\Pages\ListMembers;

/**
 * Who belongs to this organization, and as what.
 *
 * Read-only in this milestone: the roles exist and are enforced, and changing
 * them is a screen the project does not have yet. That is recorded in
 * docs/assumptions.md rather than left for a reviewer to wonder about.
 */
final class MemberResource extends Resource
{
    protected static ?string $model = OrganizationMember::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Members';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        return parent::getEloquentQuery()
            ->where('organization_id', $tenant?->organizationId->value)
            ->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Name')->searchable(),
                TextColumn::make('user.email')->label('Email')->searchable()->color('gray'),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(static fn(Role $state): string => $state->label())
                    ->color(static fn(Role $state): string => $state === Role::Owner ? 'success' : 'gray'),
                TextColumn::make('user.last_signed_in_at')
                    ->label('Last signed in')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('never'),
                TextColumn::make('created_at')->label('Member since')->dateTime('Y-m-d'),
            ])
            ->defaultSort('created_at')
            ->emptyStateHeading('No members');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
        ];
    }
}
