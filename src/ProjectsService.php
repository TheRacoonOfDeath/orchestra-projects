<?php

declare(strict_types=1);

namespace Orchestra\Projects;

use Orchestra\Projects\Assignment\Assignment;
use Closure;
use Orchestra\Projects\Assignment\AssignmentHistory;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\Ui\PartChangesView;

/**
 * The public API of orchestra-projects for other plugins, e.g. the email groups. It reads, and
 * changes nothing but a member's part in a piece ({@see setPart()}).
 *
 *     $projects = \Orchestra\Projects\Plugin::instance()->projects();
 *
 * It does not check permissions, except for {@see partChanges()}. Other plugins must not access the
 * tables directly.
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
        /** @var Closure(): ?PartChangesView Null without orchestra-email 0.10. */
        private readonly Closure $partChangesView,
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
     * The parts of the piece the members played in other projects: `true` for the part of their
     * latest assignment of the piece, `false` for parts they played before. orchestra-seating
     * colors the voices with it. Since 0.30.
     *
     * @return array<int, array<int, bool>> user ID => part ID => whether it is the latest
     */
    public function playedParts(int $projectId, int $pieceId): array
    {
        return AssignmentHistory::playedParts($projectId, $this->assignments->forPiece($pieceId));
    }

    /**
     * Gives the member the part in the piece, replacing the part they had in it, or removes their
     * part in the piece (`$partId` null). The rules of the assignments tab apply: the project is
     * neither a draft nor completed, the piece is in the program, the part belongs to
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

    /**
     * The notice about the parts changed since the members were last informed, with "Inform members
     * about changes" and "Don't inform members", as on the assignments tab; instead the review of the
     * changes when the page asks for it ({@see reviewingPartChanges()}). Its forms return to the
     * current page. Empty unless the project is in Parts assigned and has changes, orchestra-email
     * 0.10 is running and the current user may manage assignments. orchestra-seating shows it on the
     * seating tab. Since 0.35.
     */
    public function partChanges(Project $project): string
    {
        return ($this->partChangesView)()?->render($project) ?? '';
    }

    /**
     * Whether the current page asks for the review of the changed parts. Show nothing but
     * {@see partChanges()} then, unless it is empty. Since 0.35.
     */
    public static function reviewingPartChanges(): bool
    {
        return ($_GET[PartChangesView::PARAMETER] ?? null) === PartChangesView::REVIEW;
    }
}
