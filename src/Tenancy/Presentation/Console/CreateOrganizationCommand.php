<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Exception\DomainException;
use Metered\Tenancy\Application\Command\ProvisionTenant;
use Metered\Tenancy\Application\Command\ProvisionTenantHandler;
use Metered\Tenancy\Domain\Environment;

/**
 * Bootstraps a tenant from the command line: organization, first project,
 * first key.
 *
 * This is how an operator creates the tenant that has nobody to create it —
 * the first one on a fresh installation, and every tenant on an instance that
 * does not run in demo mode.
 *
 * It prints the token once. There is no second chance and the command says so,
 * because a secret that could be printed again would have to be stored.
 */
final class CreateOrganizationCommand extends Command
{
    protected $signature = 'org:create
        {name : The name of the organization}
        {--project=Production : The name of its first project}
        {--environment=test : live or test}
        {--currency=EUR : The currency everything under the project is priced in}
        {--json : Print the result as one JSON object, for scripts}
        {--demo : Create it as a demo organization, which demo mode may purge — only in demo mode}';

    protected $description = 'Create an organization with its first project and API key';

    public function handle(ProvisionTenantHandler $handler, Repository $config): int
    {
        $demo = $this->option('demo') === true;

        // A demo organization is deleted, money history and all, when it is
        // idle or the demo is reset. Only an instance that is a demo may
        // create one, so a stray flag cannot doom a real tenant.
        if ($demo && $config->get('metered.demo.enabled') !== true) {
            $this->components->error('Demo organizations exist only in demo mode (APP_DEMO=true).');

            return self::INVALID;
        }

        $environment = Environment::tryFrom((string) $this->option('environment'));

        if ($environment === null) {
            $this->components->error('The environment must be "live" or "test".');

            return self::INVALID;
        }

        try {
            $tenant = $handler->handle(new ProvisionTenant(
                organizationName: (string) $this->argument('name'),
                actor: Actor::system('console:org:create'),
                projectName: (string) $this->option('project'),
                environment: $environment,
                currency: (string) $this->option('currency'),
                demo: $demo,
            ));
        } catch (DomainException $failure) {
            // A rule the domain refused — an empty name, an unknown currency.
            // The operator needs the reason, not a stack trace.
            $this->components->error($failure->getMessage());

            return self::INVALID;
        }

        if ($this->option('json') === true) {
            // For tooling that provisions a tenant and goes on to use it — the
            // demo seed above all. Still the only time the secret is shown.
            $this->line(json_encode([
                'organization' => ['id' => $tenant->organization->id->value, 'slug' => $tenant->organization->slug->value],
                'project' => ['id' => $tenant->project->id->value, 'slug' => $tenant->project->slug->value, 'currency' => $tenant->project->currency],
                'key' => ['prefix' => $tenant->apiKey->prefix, 'secret' => $tenant->secret->reveal()],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Organization "%s" is ready.', $tenant->organization->name));

        $this->table(['', ''], [
            ['Organization', $tenant->organization->slug->value . '  ' . $tenant->organization->id->value],
            ['Project', $tenant->project->slug->value . '  ' . $tenant->project->id->value],
            ['Environment', $tenant->project->environment->value],
            ['Currency', $tenant->project->currency],
            ['Key', $tenant->apiKey->name . '  ' . $tenant->apiKey->prefix],
        ]);

        $this->newLine();
        $this->components->warn('This is the only time the key is shown. Store it now.');
        $this->line($tenant->secret->reveal());
        $this->newLine();

        return self::SUCCESS;
    }
}
