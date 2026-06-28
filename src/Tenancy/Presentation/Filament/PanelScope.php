<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Membership;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;

/**
 * Which organization and project the person at the keyboard is looking at.
 *
 * Both halves live in the session and both are re-derived from the signed-in
 * person's memberships on every read. A session that names an organization the
 * person has been removed from resolves to nothing, not to the stale scope it
 * was holding — which is the difference between an access change taking effect
 * and merely being recorded.
 *
 * Resolved per request rather than kept as a singleton. Under Octane a
 * singleton holding a request or a session belongs to whoever created it, and
 * the next request would inherit their scope while every query kept succeeding.
 */
final readonly class PanelScope
{
    public const string SESSION_KEY = 'metered.panel_scope';

    public function __construct(
        private Request $request,
        private MembershipRepository $memberships,
        private ProjectRepository $projects,
    ) {}

    /**
     * The scope to filter every panel query by, or null when the person has
     * nothing to look at.
     */
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

    /**
     * Moves the scope to another project, if the person is a member of the
     * organization that owns it. Anything else leaves the scope untouched:
     * a project id typed into a form is a request, not an instruction.
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
     * Every project the person can reach, across every organization they
     * belong to — the contents of the switcher.
     *
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

        // No choice made yet, or a choice that no longer belongs to this
        // person: fall back to the first project they can reach and remember
        // it, so the rest of the request sees one consistent scope.
        $first = $available[0];
        $this->remember($first->id->value);

        return $first;
    }

    /**
     * A request without a session still has a scope — it just cannot carry a
     * choice between requests, and falls back to the first project each time.
     * Console commands and stateless requests reach this.
     */
    private function remember(string $projectId): void
    {
        if ($this->request->hasSession()) {
            $this->request->session()->put(self::SESSION_KEY, $projectId);
        }
    }

    private function userId(): ?Uuid
    {
        // The guard rather than the request: a request only knows its user
        // once authentication middleware has run, and this is also read from
        // Livewire components, which build their own.
        $id = Auth::user()?->getAuthIdentifier();

        return is_string($id) && Uuid::isValid($id) ? Uuid::fromString($id) : null;
    }
}
