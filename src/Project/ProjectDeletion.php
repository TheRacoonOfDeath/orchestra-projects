<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\ParticipationRepository;

/**
 * Who may delete which project: administrators every project, organizers only projects that were
 * never opened for participation, so that deleting never loses participation or assignment history.
 * Deleting is permanent; {@see ProjectCleanup} removes the project's rows in all tables.
 */
final class ProjectDeletion
{
    public function __construct(private readonly ParticipationRepository $participations)
    {
    }

    public function canDelete(Project $project): bool
    {
        if (\current_user_can(Capabilities::PROJECT_DELETE)) {
            return true;
        }

        return \current_user_can(Capabilities::PROJECT_DELETE_UNOPENED) && self::neverOpened(
            $project->status,
            ProjectRepository::meta($project->id, ProjectPostType::META_OPENED_ON),
            $this->participations->forProject($project->id) !== []
        );
    }

    /**
     * A project was never opened while it is a draft without participation records. The day of the
     * last opening is only stored since 0.41; projects opened before have participation records.
     *
     * @param ?string $openedOn the day the project was last opened (Y-m-d)
     */
    public static function neverOpened(ProjectStatus $status, ?string $openedOn, bool $hasParticipation): bool
    {
        return $status === ProjectStatus::Draft && $openedOn === null && !$hasParticipation;
    }

    /**
     * @return bool whether the project was deleted
     */
    public function delete(Project $project): bool
    {
        return \wp_delete_post($project->id, true) instanceof \WP_Post;
    }
}
