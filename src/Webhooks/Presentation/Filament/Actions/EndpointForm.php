<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Actions;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Metered\Webhooks\Domain\Endpoint\EventType;

final class EndpointForm
{
    /**
     * @return list<Component|TextInput|CheckboxList|Toggle>
     */
    public static function fields(bool $withEnabled): array
    {
        $fields = [
            TextInput::make('url')->label('URL')->placeholder('https://hooks.example.com/metered')->required()->maxLength(2048),
            TextInput::make('description')->maxLength(255),
            CheckboxList::make('events')
                ->options(array_combine(EventType::names(), EventType::names()))
                ->required()
                ->columns(2),
        ];

        if ($withEnabled) {
            $fields[] = Toggle::make('enabled')->label('Deliver to this endpoint');
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    public static function events(array $data): array
    {
        $events = $data['events'] ?? [];

        return is_array($events) ? array_values(array_filter($events, is_string(...))) : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
