<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Assignment\Assignment;
use Orchestra\Projects\Assignment\AssignmentHistory;
use Orchestra\Projects\Assignment\HistoryMark;
use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class AssignmentHistoryTest extends TestCase
{
    private const PIECE = 50;
    private const PART = 500;
    private const OTHER_PART = 501;
    private const CURRENT = 9;

    public function testLastTimeIsTheLatestAssignmentOfThePieceWhateverTheProjectDates(): void
    {
        $statuses = [1 => ProjectStatus::Completed, 2 => ProjectStatus::Preparation];
        $assignments = [
            new Assignment(1, 1, self::PIECE, self::OTHER_PART, 10),
            new Assignment(2, 2, self::PIECE, self::PART, 10), // user 10: latest is this part
            new Assignment(3, 1, self::PIECE, self::PART, 11),
            new Assignment(4, 2, self::PIECE, self::OTHER_PART, 11), // user 11: latest is another part
        ];

        $marks = AssignmentHistory::marks(self::CURRENT, self::PART, $statuses, $assignments, [10, 11, 12]);

        $this->assertSame([
            10 => [HistoryMark::LastTime],
            11 => [HistoryMark::Completed],
            12 => [HistoryMark::Never],
        ], $marks);
    }

    public function testCompletedAndArchivedProjectsCountAsCompleted(): void
    {
        $statuses = [1 => ProjectStatus::Archived, 2 => ProjectStatus::Completed, 3 => ProjectStatus::Open];
        $assignments = [
            new Assignment(1, 1, self::PIECE, self::PART, 10),
            new Assignment(2, 2, self::PIECE, self::PART, 11),
            new Assignment(3, 3, self::PIECE, self::PART, 12),
        ];

        $marks = AssignmentHistory::marks(self::CURRENT, self::PART, $statuses, $assignments, [10, 11, 12]);

        $this->assertSame([
            10 => [HistoryMark::LastTime, HistoryMark::Completed],
            11 => [HistoryMark::LastTime, HistoryMark::Completed],
            12 => [HistoryMark::LastTime],
        ], $marks);
    }

    public function testPlayingThePartOnlyInAnUnfinishedProjectBeforeIsNoMark(): void
    {
        $statuses = [1 => ProjectStatus::Preparation, 2 => ProjectStatus::Open];
        $assignments = [
            new Assignment(1, 1, self::PIECE, self::PART, 10),
            new Assignment(2, 2, self::PIECE, self::OTHER_PART, 10),
        ];

        $marks = AssignmentHistory::marks(self::CURRENT, self::PART, $statuses, $assignments, [10]);

        $this->assertSame([10 => []], $marks);
    }

    public function testIgnoresTheCurrentProjectAndDeletedUsers(): void
    {
        $statuses = [1 => ProjectStatus::Completed, self::CURRENT => ProjectStatus::Open];
        $assignments = [
            new Assignment(1, 1, self::PIECE, self::PART, 10),
            new Assignment(2, self::CURRENT, self::PIECE, self::OTHER_PART, 10),
            new Assignment(3, 1, self::PIECE, self::PART, null),
        ];

        $marks = AssignmentHistory::marks(self::CURRENT, self::PART, $statuses, $assignments, [10]);

        $this->assertSame([10 => [HistoryMark::LastTime, HistoryMark::Completed]], $marks);
    }
}
