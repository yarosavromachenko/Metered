<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Exception\DomainException;
use Metered\Tenancy\Application\Command\AddMember;
use Metered\Tenancy\Application\Command\AddMemberHandler;
use Metered\Tenancy\Application\Command\EmailAlreadyRegistered;
use Metered\Tenancy\Application\Command\TenantNotFound;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Domain\Slug;

/**
 * Without --password, one is generated and printed once.
 */
final class AddMemberCommand extends Command
{
    protected $signature = 'org:member
        {organization : The organization\'s slug}
        {email : The address they sign in with}
        {--name= : Their name, defaults to the part of the address before the @}
        {--role=viewer : owner, admin, billing_operator or viewer}
        {--password= : Their password; generated and printed when not given}';

    protected $description = 'Add a person with a role to an organization';

    public function handle(AddMemberHandler $handler): int
    {
        $role = Role::tryFrom((string) $this->option('role'));

        if ($role === null) {
            $this->components->error('The role must be owner, admin, billing_operator or viewer.');

            return self::INVALID;
        }

        $email = (string) $this->argument('email');
        $given = $this->option('password');
        $password = is_string($given) && $given !== '' ? $given : bin2hex(random_bytes(9));
        $name = $this->option('name');

        try {
            $handler->handle(new AddMember(
                organization: Slug::fromString((string) $this->argument('organization')),
                name: is_string($name) && $name !== '' ? $name : ucfirst(explode('@', $email)[0]),
                email: $email,
                plainPassword: $password,
                role: $role,
                actor: Actor::system('console:org:member'),
            ));
        } catch (DomainException|TenantNotFound|EmailAlreadyRegistered $refused) {
            $this->components->error($refused->getMessage());

            return self::INVALID;
        }

        $this->components->info(sprintf('%s can sign in as %s.', $email, $role->value));

        if (! is_string($given) || $given === '') {
            $this->components->warn('Their password, shown this once:');
            $this->line($password);
        }

        return self::SUCCESS;
    }
}
