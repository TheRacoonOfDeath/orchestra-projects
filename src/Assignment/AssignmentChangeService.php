<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;

/**
 * Remembers which parts changed while a project is in Parts assigned, so that the members can be
 * informed about them.
 *
 * A snapshot of the assignments is taken when the project enters Parts assigned, and again when
 * the members were informed or the changes were dismissed. The changes are the difference between
 * the snapshot and the current assignments, so every way of changing parts counts (also the
 * seating of orchestra-seating, participation changes and pieces removed from the program).
 * Leaving Parts assigned forgets them.
 */
final class AssignmentChangeService
{
    public function __construct(
        private readonly InformedRepository $informed,
        private readonly AssignmentRepository $assignments,
    ) {
    }

    /**
     * @return list<PartChange> nothing unless the project is in Parts assigned
     */
    public function pending(Project $project): array
    {
        if ($project->status !== ProjectStatus::PartsAssigned) {
            return [];
        }
        // Projects that were in Parts assigned before 0.33 start without changes.
        if (!$this->informed->exists($project->id)) {
            $this->remember($project->id);
            return [];
        }

        return AssignmentChanges::between(
            $this->informed->snapshot($project->id),
            AssignmentChanges::map($this->assignments->forProject($project->id))
        );
    }

    /**
     * The current assignments are what the members know: after informing them, or to forget the
     * changes without informing them.
     */
    public function remember(int $projectId): void
    {
        $this->informed->replace($projectId, AssignmentChanges::map($this->assignments->forProject($projectId)));
    }

    /**
     * @internal Hooked to `orchestra_projects_status_changed`.
     */
    public function statusChanged(int $projectId, string $newStatus, string $previousStatus): void
    {
        if ($previousStatus === ProjectStatus::PartsAssigned->value) {
            $this->informed->delete($projectId);
        }
        if ($newStatus === ProjectStatus::PartsAssigned->value) {
            $this->remember($projectId);
        }
    }
}
