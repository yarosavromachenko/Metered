<?php

declare(strict_types=1);

namespace Tests\Support;

use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Infrastructure\Eloquent\User;
use Metered\Tenancy\Presentation\Filament\PanelScope;

use function Pest\Laravel\actingAs;

use RuntimeException;

/**
 * Signs a member of a fresh organization into the panel, with its project in
 * the panel scope — the state every catalog screen and action starts from.
 */
final class PanelSession
{
    public static function signIn(string $organizationSlug = 'acme', Role $role = Role::Admin): Project
    {
        $project = TenantFactory::tenant($organizationSlug);
        $member = TenantFactory::member($project->organizationId, $role, $role->value . '@' . $organizationSlug . '.example');

        $user = User::query()->find($member->userId?->value);
        actingAs($user instanceof User ? $user : throw new RuntimeException('No user.'));

        app('request')->setLaravelSession(app('session.store'));
        app(PanelScope::class)->switchTo($project->id->value);

        return $project;
    }
}
