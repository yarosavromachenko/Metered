<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Auth;

use Filament\Auth\Pages\Register;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Events\Dispatcher;
use Metered\Tenancy\Application\Command\RegisterDemoTenant;
use Metered\Tenancy\Application\Command\RegisterDemoTenantHandler;
use Metered\Tenancy\Application\Contract\DemoDataRequested;
use Metered\Tenancy\Infrastructure\Eloquent\User;
use RuntimeException;
use SensitiveParameter;

/**
 * Demo sign-up (ADR-0016). Adds the company name to Filament's form; slug,
 * currency and environment use defaults. Goes through RegisterDemoTenantHandler.
 */
final class RegisterTenant extends Register
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            TextInput::make('organization')
                ->label('Company')
                ->required()
                ->maxLength(120)
                ->helperText('Your organization gets a test project and an API key straight away.'),
        ]);
    }

    public function getSubheading(): string
    {
        // No mail infrastructure (ADR-0017).
        return 'Demo accounts have no password reset. A forgotten password means a new account.';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): User
    {
        $registered = app(RegisterDemoTenantHandler::class)->handle(new RegisterDemoTenant(
            name: $this->text($data['name'] ?? null),
            email: $this->text($data['email'] ?? null),
            plainPassword: $this->text($data['password'] ?? null),
            organizationName: $this->text($data['organization'] ?? null),
        ));

        // Filled in the background after commit.
        app(Dispatcher::class)->dispatch(new DemoDataRequested(
            $registered->tenant->organization->id->value,
            $registered->tenant->secret->reveal(),
        ));

        $user = User::query()->find($registered->userId->value);

        // Just written by the handler; a miss is a bug.
        return $user instanceof User
            ? $user
            : throw new RuntimeException('The account was created but could not be read back.');
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
