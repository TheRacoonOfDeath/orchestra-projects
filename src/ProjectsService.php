<?php

declare(strict_types=1);

namespace Orchestra\Projects;

use Orchestra\Projects\Assignment\Assignment;
use Closure;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatus;

/**
 * The public API of orchestra-projects for other plugins, e.g. the email groups. It reads, and
 * changes nothing but a member's part in a piece ({@see setPart()}).
 *
 *     $projects = \Orchestra\Projects\Plugin::instance()->projects();
 *
 * It does not check permissions. Other plugins must not access the tables directly.
 */
final class ProjectsService
{
    /**
     * Filters the tabs of a project: `(array<string, string> $tabs, Project $project)`, tab key =>
     * label. Other plugins add their own tabs, e.g. orchestra-seating; check the current user's
     * capabilities there.
     */
    public const TABS_FILTER = 'orchestra_projects_tabs';

    /**
     * Filters the content of a tab added with {@see TABS_FILTER}:
     * `(string $html, string $tab, Project $project, string $tabUrl)`. `$tabUrl` links to the tab,
     * on the projects page or in wp-admin; forms return to it.
     */
    public const TAB_CONTENT_FILTER = 'orchestra_projects_tab_content';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProgramRepository $program,
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
        /** @var Closure(): AssignmentService Created on first use, once orchestra-library is loaded. */
        private readonly Closure $assignmentService,
    ) {
    }

    public function project(int $projectId): ?Project
    {
        return $this->projects->find($projectId);
    }

    /**
     * @param list<ProjectStatus> $statuses
     * @return list<Project> newest first
     */
    public function projects(array $statuses = []): array
    {
        return $this->projects->all($statuses);
    }

    /**
     * @return list<int> piece IDs in program order
     */
    public function programPieceIds(int $projectId): array
    {
        return $this->program->pieceIds($projectId);
    }

    /**
     * @return list<int> IDs of the users with a participation record, optionally only with the status
     */
    public function participantIds(int $projectId, ?ParticipationStatus $status = null): array
    {
        return \array_values(\array_filter(\array_map(
            static fn (Participation $p): ?int => $p->userId,
            $this->participations->forProject($projectId, $status)
        )));
    }

    /**
     * @param list<int>|null $partIds Only assignments to these parts
     * @return list<int> IDs of the users assigned in the project
     */
    public function assignedUserIds(int $projectId, ?array $partIds = null): array
    {
        $userIds = [];
        foreach ($this->assignments->forProject($projectId) as $assignment) {
            if ($assignment->userId !== null && ($partIds === null || \in_array($assignment->partId, $partIds, true))) {
                $userIds[$assignment->userId] = $assignment->userId;
            }
        }

        return \array_values($userIds);
    }

    /**
     * @return list<Assignment>
     */
    public function assignments(int $projectId): array
    {
        return $this->assignments->forProject($projectId);
    }

    /**
     * Gives the member the part in the piece, replacing the part they had in it, or removes their
     * part in the piece (`$partId` null). The rules of the assignments tab apply: the project is
     * open for participation or in preparation, the piece is in the program, the part belongs to
     * it and the member has accepted. The caller checks that the user may manage assignments
     * (`orchestra_projects_assignment_manage`). Since 0.27.
     *
     * @return string|null An error message, or null on success
     */
    public function setPart(int $projectId, int $pieceId, int $userId, ?int $partId, int $actorId): ?string
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            return \__('The project does not exist.', 'orchestra-projects');
        }

        return ($this->assignmentService)()->setPart($project, $pieceId, $userId, $partId, $actorId);
    }
}
