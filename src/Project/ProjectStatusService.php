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

    /**
     * Moves a project that is over step by step to Completed, as the status buttons would. Other
     * projects move one step at a time with the status buttons.
     *
     * @param string $today Y-m-d in the site's time zone
     * @return string|null An error message, or null on success
     */
    public function complete(Project $project, string $today): ?string
    {
        if (!\in_array($project->status, [ProjectStatus::Open, ProjectStatus::Preparation], true)) {
            return \__('Only open projects and projects in preparation can be completed.', 'orchestra-projects');
        }
        if (!$project->endedBefore($today)) {
            return \__('The project is not over yet. Change its status step by step instead.', 'orchestra-projects');
        }

        while ($project->status !== ProjectStatus::Completed) {
            $next = $project->status->next();
            $error = $next === null ? null : $this->change($project, $next);
            if ($next === null || $error !== null) {
                return $error;
            }
            $project = $this->projects->find($project->id) ?? $project;
        }

        return null;
    }
}
