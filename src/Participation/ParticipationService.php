<?php

declare(strict_types=1);

namespace Orchestra\Projects\Participation;

use Orchestra\Core\Authorization\Roles;
use Orchestra\Members\MembersService;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatus;
use WP_User;

final class ParticipationService
{
    /** Roles that play in projects. Conductors do not. */
    public const PLAYER_ROLES = [Roles::MEMBER, Roles::ORGANIZER];

    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
        private readonly ProjectRepository $projects,
        private readonly MembersService $members,
    ) {
    }

    /**
     * Creates Open records for all active players who have none yet. Called when a project is
     * opened for participation.
     */
    public function createForProject(int $projectId): int
    {
        return $this->participations->createMissing($projectId, $this->members->activeMemberIds(self::PLAYER_ROLES));
    }

    /**
     * Creates Open records in all open projects for a player who was created, reactivated or got a
     * player role.
     *
     * @internal Hooked to `orchestra_members_member_activated` and `set_user_role`.
     */
    public function createForUser(int $userId): void
    {
        $user = \get_userdata($userId);
        if (
            !$user instanceof WP_User
            || \array_intersect(self::PLAYER_ROLES, $user->roles) === []
            || !$this->members->isActive($userId)
        ) {
            return;
        }

        foreach ($this->projects->all([ProjectStatus::Open]) as $project) {
            $this->participations->createMissing($project->id, [$userId]);
        }
    }

    /**
     * A member's own, final response.
     *
     * @return string|null An error message, or null on success
     */
    public function respond(Participation $participation, int $userId, ParticipationStatus $response): ?string
    {
        $project = $this->projects->find($participation->projectId);
        if ($project === null || $participation->userId !== $userId) {
            return \__('This response is not possible.', 'orchestra-projects');
        }

        $refusal = ParticipationRules::responseRefusal($project->status, $participation->status, $response);
        if ($refusal !== null) {
            return $refusal;
        }

        $this->participations->setStatus($participation->id, $response, $userId, true);

        return null;
    }

    /**
     * The member's own comment on their participation.
     *
     * @return string|null An error message, or null on success
     */
    public function comment(Participation $participation, int $userId, string $comment): ?string
    {
        $project = $this->projects->find($participation->projectId);
        if ($project === null || $participation->userId !== $userId) {
            return \__('This comment is not possible.', 'orchestra-projects');
        }
        if (!ParticipationRules::commentsOpen($project->status)) {
            return \__(
                'Comments can only be changed while the project is open or in preparation.',
                'orchestra-projects'
            );
        }

        $this->participations->setMemberComment(
            $participation->id,
            ParticipationRules::text($comment, ParticipationRules::COMMENT_MAX_LENGTH)
        );

        return null;
    }

    /**
     * The organizers' note on a participation, which only organizers see.
     */
    public function note(Participation $participation, string $note): void
    {
        $this->participations->setOrganizerNote(
            $participation->id,
            ParticipationRules::text($note, ParticipationRules::NOTE_MAX_LENGTH)
        );
    }

    /**
     * An organizer changes a decision. Leaving Accepted removes the member's assignments in the
     * project, which must be confirmed.
     *
     * @return string|null An error message, or null on success
     */
    public function change(
        Participation $participation,
        ParticipationStatus $status,
        int $organizerId,
        bool $assignmentRemovalConfirmed,
    ): ?string {
        if ($participation->status === $status) {
            return null;
        }

        $removesAssignments = $participation->status === ParticipationStatus::Accepted
            && $participation->userId !== null
            && $this->assignments->forProjectAndUser($participation->projectId, $participation->userId) !== [];
        if ($removesAssignments && !$assignmentRemovalConfirmed) {
            return \__(
                'The member has assignments in this project. Confirm that they are removed.',
                'orchestra-projects'
            );
        }

        $this->participations->setStatus($participation->id, $status, $organizerId, false);
        if ($removesAssignments) {
            $this->assignments->deleteForProjectAndUser($participation->projectId, $participation->userId);
        }

        return null;
    }
}
