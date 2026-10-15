<?php

declare(strict_types=1);

namespace Orchestra\Projects\Program;

use Orchestra\Library\LibraryService;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Project\Project;

final class ProgramService
{
    public function __construct(
        private readonly ProgramRepository $program,
        private readonly AssignmentRepository $assignments,
        private readonly LibraryService $library,
    ) {
    }

    /**
     * @return string|null An error message, or null on success
     */
    public function add(Project $project, int $pieceId): ?string
    {
        if (!$project->status->programEditable()) {
            return self::lockedMessage();
        }
        $piece = $this->library->piece($pieceId);
        if ($piece === null || !$piece->published) {
            return \__('The piece does not exist.', 'orchestra-projects');
        }
        if ($piece->archived) {
            return \__('Archived pieces cannot be added to a program.', 'orchestra-projects');
        }
        if ($this->program->contains($project->id, $pieceId)) {
            return \__('The piece is already in the program.', 'orchestra-projects');
        }

        $this->program->add($project->id, $pieceId);

        return null;
    }

    /**
     * Removes the piece and its assignments in the project, which must be confirmed.
     *
     * @return string|null An error message, or null on success
     */
    public function remove(Project $project, int $pieceId, bool $assignmentRemovalConfirmed): ?string
    {
        if (!$project->status->programEditable()) {
            return self::lockedMessage();
        }
        if (!$assignmentRemovalConfirmed && $this->assignmentCount($project->id, $pieceId) > 0) {
            return \__('The piece has assignments. Confirm that they are removed.', 'orchestra-projects');
        }

        $this->assignments->deleteForProjectAndPiece($project->id, $pieceId);
        $this->program->remove($project->id, $pieceId);

        return null;
    }

    /**
     * @param int $offset -1 to move the piece up, 1 to move it down
     * @return string|null An error message, or null on success
     */
    public function move(Project $project, int $pieceId, int $offset): ?string
    {
        if (!$project->status->programEditable()) {
            return self::lockedMessage();
        }
        $pieceIds = $this->program->pieceIds($project->id);
        $from = \array_search($pieceId, $pieceIds, true);
        $to = \is_int($from) ? $from + $offset : -1;
        if (!\is_int($from) || !isset($pieceIds[$to])) {
            return null;
        }

        [$pieceIds[$from], $pieceIds[$to]] = [$pieceIds[$to], $pieceIds[$from]];
        $this->program->reorder($project->id, \array_values($pieceIds));

        return null;
    }

    public function assignmentCount(int $projectId, int $pieceId): int
    {
        $count = 0;
        foreach ($this->assignments->forProject($projectId) as $assignment) {
            $count += $assignment->pieceId === $pieceId ? 1 : 0;
        }

        return $count;
    }

    private static function lockedMessage(): string
    {
        return \__('The program of a completed or archived project cannot be changed.', 'orchestra-projects');
    }
}
