<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

use Orchestra\Projects\Project\ProjectStatus;

/**
 * Derives from the assignments of the other projects what members played of a piece before.
 *
 * Only the order in which the assignments were made counts, not the dates of the projects: the
 * latest assignment of the piece to a member is the one with the highest ID (moving a member to
 * another part creates a new assignment).
 */
final class AssignmentHistory
{
    /**
     * @param array<int, ProjectStatus> $statuses project ID => status, for the projects of the assignments
     * @param list<Assignment> $pieceAssignments All assignments of the part's piece, in any project
     * @param list<int> $userIds
     * @return array<int, list<HistoryMark>> user ID => marks
     */
    public static function marks(
        int $currentProjectId,
        int $partId,
        array $statuses,
        array $pieceAssignments,
        array $userIds,
    ): array {
        /** @var array<int, Assignment> $latest user ID => their latest assignment of the piece */
        $latest = [];
        $played = [];
        $completed = [];
        foreach ($pieceAssignments as $assignment) {
            $userId = $assignment->userId;
            if ($userId === null || $assignment->projectId === $currentProjectId) {
                continue;
            }
            if (!isset($latest[$userId]) || $assignment->id > $latest[$userId]->id) {
                $latest[$userId] = $assignment;
            }
            if ($assignment->partId !== $partId) {
                continue;
            }
            $played[$userId] = true;
            $status = $statuses[$assignment->projectId] ?? null;
            if ($status === ProjectStatus::Completed || $status === ProjectStatus::Archived) {
                $completed[$userId] = true;
            }
        }

        $marks = [];
        foreach ($userIds as $userId) {
            $userMarks = [];
            if (isset($latest[$userId]) && $latest[$userId]->partId === $partId) {
                $userMarks[] = HistoryMark::LastTime;
            }
            if (isset($completed[$userId])) {
                $userMarks[] = HistoryMark::Completed;
            }
            if (!isset($played[$userId])) {
                $userMarks[] = HistoryMark::Never;
            }
            $marks[$userId] = $userMarks;
        }

        return $marks;
    }
}
