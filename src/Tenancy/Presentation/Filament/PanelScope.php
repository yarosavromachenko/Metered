<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope as PanelScopeContract;
use Metered\Tenancy\Domain\Membership;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;

/**
 * The selection is kept in the session and checked against memberships on
 * every read, so removed access takes effect immediately. Resolved per request
 * (Octane). Other modules use {@see PanelScopeContract}.
 */
final readonly class PanelScope implements PanelScopeContract
{
    public const string SESSION_KEY = 'metered.panel_scope';

    public function __construct(
        private Request $request,
        private MembershipRepository $memberships,
        private ProjectRepository $projects,
    ) {}

    public function tenant(): ?TenantContext
    {
        return $this->current()?->tenant();
    }

    public function project(): ?Project
    {
        return $this->current();
    }

    public function membership(): ?Membership
    {
        $project = $this->current();

        if (!$project instanceof Project) {
            return null;
        }

        $userId = $this->userId();

        return $userId instanceof Uuid ? $this->memberships->find($project->organizationId, $userId) : null;
    }

    public function may(Permission $permission): bool
    {
        return $this->membership()?->may($permission) === true;
    }

    public function actor(): Actor
    {
        return PanelActor::current();
    }

    /**
     * Ignored unless the user is a member of the project's organization.
     */
    public function switchTo(string $projectId): bool
    {
        foreach ($this->available() as $project) {
            if ($project->id->value === $projectId) {
                $this->remember($projectId);

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Project>
     */
    public function available(): array
    {
        $userId = $this->userId();

        if (!$userId instanceof Uuid) {
            return [];
        }

        $projects = [];

        foreach ($this->memberships->forUser($userId) as $membership) {
            foreach ($this->projects->listForOrganization($membership->organizationId) as $project) {
                $projects[] = $project;
            }
        }

        return $projects;
    }

    private function current(): ?Project
    {
        $available = $this->available();

        if ($available === []) {
            return null;
        }

        $selected = $this->request->hasSession()
            ? $this->request->session()->get(self::SESSION_KEY)
            : null;

        foreach ($available as $project) {
            if (is_string($selected) && $project->id->value === $selected) {
                return $project;
            }
        }

        // No valid choice: use the first reachable project and store it.
        $first = $available[0];
        $this->remember($first->id->value);

        return $first;
    }

    /**
     * Without a session (console, stateless requests) the first project is used.
     */
    private function remember(string $projectId): void
    {
        if ($this->request->hasSession()) {
            $this->request->session()->put(self::SESSION_KEY, $projectId);
        }
    }

    private function userId(): ?Uuid
    {
        // The guard, not the request: Livewire components build their own requests.
        $id = Auth::user()?->getAuthIdentifier();

        return is_string($id) && Uuid::isValid($id) ? Uuid::fromString($id) : null;
    }
}
