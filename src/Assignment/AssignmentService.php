<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

use Orchestra\Library\LibraryService;
use Orchestra\Library\Part\Part;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectRepository;

final class AssignmentService
{
    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly ParticipationRepository $participations,
        private readonly ProgramRepository $program,
        private readonly ProjectRepository $projects,
        private readonly LibraryService $library,
    ) {
    }

    /**
     * Assigns members to a part. A member who already plays another part of the piece is only
     * moved if `$move` is set.
     *
     * @param list<int> $userIds
     * @return list<string> Error messages; members without an error are assigned
     */
    public function assign(Project $project, int $partId, array $userIds, bool $move, int $actorId): array
    {
        $part = $this->library->part($partId);
        $refusal = $this->refusal($project, $part);
        if ($refusal !== null || $part === null) {
            return [$refusal ?? ''];
        }
        if ($userIds === []) {
            return [\__('Select at least one member.', 'orchestra-projects')];
        }

        $accepted = [];
        foreach ($this->participations->forProject($project->id, ParticipationStatus::Accepted) as $participation) {
            $accepted[$participation->userId ?? 0] = true;
        }
        $current = [];
        foreach ($this->assignments->forProject($project->id) as $assignment) {
            if ($assignment->pieceId === $part->pieceId && $assignment->userId !== null) {
                $current[$assignment->userId] = $assignment;
            }
        }

        $errors = [];
        foreach (\array_unique($userIds) as $userId) {
            $name = self::name($userId);
            if (!isset($accepted[$userId])) {
                /* translators: %s: member name */
                $errors[] = \sprintf(\__('%s has not accepted to participate.', 'orchestra-projects'), $name);
                continue;
            }
            $existing = $current[$userId] ?? null;
            if ($existing?->partId === $partId) {
                continue;
            }
            if ($existing !== null && !$move) {
                $errors[] = \sprintf(
                    /* translators: 1: member name, 2: part name */
                    \__('%1$s is already assigned to %2$s in this piece.', 'orchestra-projects'),
                    $name,
                    $this->library->part($existing->partId)->name ?? '?'
                );
                continue;
            }
            if ($existing !== null) {
                $this->assignments->delete($existing->id);
            }
            $this->assignments->insert($project->id, $part->pieceId, $partId, $userId, $actorId);
        }

        return $errors;
    }

    /**
     * The assignments that give every accepted member the part they played last time, in each piece
     * of the program in which they have no part yet. Members who never played the piece are left out.
     *
     * @return list<array{pieceId: int, partId: int, userId: int}>
     */
    public function lastPartAssignments(Project $project): array
    {
        $accepted = [];
        foreach ($this->participations->forProject($project->id, ParticipationStatus::Accepted) as $participation) {
            if ($participation->userId !== null) {
                $accepted[$participation->userId] = true;
            }
        }
        $assigned = [];
        foreach ($this->assignments->forProject($project->id) as $assignment) {
            $assigned[$assignment->pieceId][$assignment->userId ?? 0] = true;
        }

        $result = [];
        foreach ($this->program->pieceIds($project->id) as $pieceId) {
            $partIds = \array_map(static fn (Part $part): int => $part->id, $this->library->parts($pieceId));
            $lastParts = AssignmentHistory::lastParts($project->id, $this->assignments->forPiece($pieceId));
            foreach ($lastParts as $userId => $partId) {
                if (
                    isset($accepted[$userId])
                    && !isset($assigned[$pieceId][$userId])
                    && \in_array($partId, $partIds, true)
                ) {
                    $result[] = ['pieceId' => $pieceId, 'partId' => $partId, 'userId' => $userId];
                }
            }
        }

        return $result;
    }

    /**
     * Makes the assignments of {@see lastPartAssignments()}.
     *
     * @return int|string The number of assignments made, or an error message
     */
    public function assignLastParts(Project $project, int $actorId): int|string
    {
        if (!$project->status->assignmentsEditable()) {
            return self::lockedMessage();
        }

        $assignments = $this->lastPartAssignments($project);
        foreach ($assignments as ['pieceId' => $pieceId, 'partId' => $partId, 'userId' => $userId]) {
            $this->assignments->insert($project->id, $pieceId, $partId, $userId, $actorId);
        }

        return \count($assignments);
    }

    /**
     * @return string|null An error message, or null on success
     */
    public function unassign(Project $project, int $assignmentId): ?string
    {
        $assignment = $this->assignments->find($assignmentId);
        if ($assignment === null || $assignment->projectId !== $project->id) {
            return \__('The assignment does not exist.', 'orchestra-projects');
        }
        if (!$project->status->assignmentsEditable()) {
            return self::lockedMessage();
        }

        $this->assignments->delete($assignmentId);

        return null;
    }

    /**
     * History marks for assigning members to the part, see {@see AssignmentHistory}.
     *
     * @param list<int> $userIds
     * @return array<int, list<HistoryMark>>
     */
    public function historyMarks(Project $project, Part $part, array $userIds): array
    {
        $assignments = $this->assignments->forPiece($part->pieceId);
        $statuses = [];
        foreach ($assignments as $assignment) {
            if (!isset($statuses[$assignment->projectId])) {
                $other = $this->projects->find($assignment->projectId);
                if ($other !== null) {
                    $statuses[$assignment->projectId] = $other->status;
                }
            }
        }

        return AssignmentHistory::marks($project->id, $part->id, $statuses, $assignments, $userIds);
    }

    private function refusal(Project $project, ?Part $part): ?string
    {
        if (!$project->status->assignmentsEditable()) {
            return self::lockedMessage();
        }
        if ($part === null || !$this->program->contains($project->id, $part->pieceId)) {
            return \__('The part does not belong to a piece of the program.', 'orchestra-projects');
        }

        return null;
    }

    private static function name(int $userId): string
    {
        $user = \get_userdata($userId);

        return $user === false ? '#' . $userId : $user->display_name;
    }

    private static function lockedMessage(): string
    {
        return \__(
            'Assignments can only be changed while the project is open for participation or in preparation.',
            'orchestra-projects'
        );
    }
}
