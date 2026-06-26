<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Auth;

use Filament\Auth\Pages\Register;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Metered\Tenancy\Application\Command\RegisterDemoTenant;
use Metered\Tenancy\Application\Command\RegisterDemoTenantHandler;
use Metered\Tenancy\Infrastructure\Eloquent\User;
use RuntimeException;
use SensitiveParameter;

/**
 * Self-service sign-up, offered only when demo mode names this page
 * (ADR-0016). A visitor leaves this form owning an organization, a project and
 * a working API key.
 *
 * One extra field over Filament's own: the company name. Everything else the
 * new tenant needs is derived, because a sign-up that asked a stranger to
 * choose a currency, an environment and a slug before seeing anything would
 * lose most of them at the second field.
 *
 * Registration goes through the same handler the console command uses, so the
 * demo exercises the real path rather than a shortcut written for it.
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
        // Said plainly, because it is true and because there is no mail
        // infrastructure to soften it with (ADR-0017).
        return 'Demo accounts have no password reset. A forgotten password means a new account.';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $registered = app(RegisterDemoTenantHandler::class)->handle(new RegisterDemoTenant(
            name: self::text($data['name'] ?? null),
            email: self::text($data['email'] ?? null),
            password: self::text($data['password'] ?? null),
            organizationName: self::text($data['organization'] ?? null),
        ));

        $user = User::query()->find($registered->userId->value);

        // The handler just wrote it inside this transaction, so a miss here
        // means the mapping is broken rather than that the visitor did
        // anything wrong.
        return $user instanceof User
            ? $user
            : throw new RuntimeException('The account was created but could not be read back.');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
