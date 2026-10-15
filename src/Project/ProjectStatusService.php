<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

use Orchestra\Projects\Participation\ParticipationService;

final class ProjectStatusService
{
    /** Fires with the project ID, the new and the previous status value after a status change. */
    public const ACTION_STATUS_CHANGED = 'orchestra_projects_status_changed';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ParticipationService $participation,
    ) {
    }

    /**
     * @return string|null An error message, or null on success
     */
    public function change(Project $project, ProjectStatus $target): ?string
    {
        if (!$project->status->canMoveTo($target)) {
            return \__('The project cannot be moved to this status.', 'orchestra-projects');
        }

        $this->projects->setStatus($project->id, $target);
        if ($target === ProjectStatus::Open) {
            $this->participation->createForProject($project->id);
        }

        \do_action(self::ACTION_STATUS_CHANGED, $project->id, $target->value, $project->status->value);

        return null;
    }
}
