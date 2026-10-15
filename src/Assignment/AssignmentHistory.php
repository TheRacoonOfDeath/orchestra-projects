<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;

/**
 * Derives from the assignments of earlier projects whether members played a part before.
 *
 * Earlier projects are those with an earlier date ({@see Project::sortDate()}) than the current
 * one. Drafts and projects without any date are ignored.
 */
final class AssignmentHistory
{
    /**
     * @param list<Project> $projectsWithPiece All projects whose program contains the part's piece
     * @param list<Assignment> $partAssignments All assignments of the part, in any project
     * @param list<int> $userIds
     * @return array<int, HistoryMark> user ID => mark
     */
    public static function marks(
        Project $current,
        array $projectsWithPiece,
        array $partAssignments,
        array $userIds,
    ): array {
        $earlier = [];
        foreach ($projectsWithPiece as $project) {
            $date = $project->sortDate();
            if (
                $project->id !== $current->id
                && $project->status !== ProjectStatus::Draft
                && $date !== null
                && ($current->sortDate() === null || $date < $current->sortDate())
            ) {
                $earlier[$project->id] = $date;
            }
        }
        \arsort($earlier);
        $lastProjectId = \array_key_first($earlier);

        $playedIn = [];
        foreach ($partAssignments as $assignment) {
            if ($assignment->userId !== null && isset($earlier[$assignment->projectId])) {
                $playedIn[$assignment->userId][$assignment->projectId] = true;
            }
        }

        $marks = [];
        foreach ($userIds as $userId) {
            $marks[$userId] = match (true) {
                $lastProjectId !== null && isset($playedIn[$userId][$lastProjectId]) => HistoryMark::LastTime,
                isset($playedIn[$userId]) => HistoryMark::Before,
                default => HistoryMark::Never,
            };
        }

        return $marks;
    }
}
