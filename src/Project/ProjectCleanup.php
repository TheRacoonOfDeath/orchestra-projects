<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Program\ProgramRepository;

/**
 * Keeps the tables consistent when projects or users are deleted, and protects pieces and parts
 * that projects use from being deleted in orchestra-library.
 */
final class ProjectCleanup
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly StaffRepository $staff,
        private readonly ProgramRepository $program,
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
    ) {
    }

    public function register(): void
    {
        \add_action('before_delete_post', [$this, 'deleteProjectData']);
        \add_action('deleted_user', [$this, 'anonymizeUser']);
        \add_filter('orchestra_library_piece_delete_blockers', [$this, 'pieceDeleteBlockers'], 10, 2);
        \add_filter('orchestra_library_part_delete_blockers', [$this, 'partDeleteBlockers'], 10, 2);
    }

    /**
     * @internal Hooked to `before_delete_post`.
     */
    public function deleteProjectData(int $postId): void
    {
        if (\get_post_type($postId) !== ProjectPostType::NAME) {
            return;
        }

        $this->assignments->deleteForProject($postId);
        $this->participations->deleteForProject($postId);
        $this->program->deleteForProject($postId);
        $this->staff->deleteForProject($postId);
    }

    /**
     * Participation and assignments stay in the history without a reference to the person.
     *
     * @internal Hooked to `deleted_user`.
     */
    public function anonymizeUser(int $userId): void
    {
        $this->staff->deleteForUser($userId);
        $this->participations->anonymizeUser($userId);
        $this->assignments->anonymizeUser($userId);
    }

    /**
     * @internal Hooked to `orchestra_library_piece_delete_blockers`.
     * @param array<mixed> $blockers
     * @return array<mixed>
     */
    public function pieceDeleteBlockers(array $blockers, int $pieceId): array
    {
        foreach ($this->program->projectIdsWithPiece($pieceId) as $projectId) {
            /* translators: %s: project name */
            $message = \__('The piece is in the program of the project "%s".', 'orchestra-projects');
            $blockers[] = \sprintf($message, $this->projects->find($projectId)->title ?? '#' . $projectId);
        }

        return $blockers;
    }

    /**
     * @internal Hooked to `orchestra_library_part_delete_blockers`.
     * @param array<mixed> $blockers
     * @return array<mixed>
     */
    public function partDeleteBlockers(array $blockers, int $partId): array
    {
        $projectIds = [];
        foreach ($this->assignments->forPart($partId) as $assignment) {
            $projectIds[$assignment->projectId] = true;
        }
        foreach (\array_keys($projectIds) as $projectId) {
            /* translators: %s: project name */
            $message = \__('The part is assigned in the project "%s".', 'orchestra-projects');
            $blockers[] = \sprintf($message, $this->projects->find($projectId)->title ?? '#' . $projectId);
        }

        return $blockers;
    }
}
