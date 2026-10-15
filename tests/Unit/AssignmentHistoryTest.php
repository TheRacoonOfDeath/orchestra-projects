<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Assignment\Assignment;
use Orchestra\Projects\Assignment\AssignmentHistory;
use Orchestra\Projects\Assignment\HistoryMark;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class AssignmentHistoryTest extends TestCase
{
    private const PIECE = 50;
    private const PART = 500;

    public function testMarksWhoPlayedThePartLastTimeBeforeOrNever(): void
    {
        $current = self::project(4, '2028-05-01');
        $projects = [
            self::project(1, '2026-05-01'),
            self::project(2, '2027-05-01'),
            $current,
            self::project(5, '2029-05-01'), // later: ignored
        ];
        $assignments = [
            self::assignment(1, 10), // user 10 played it two projects ago
            self::assignment(2, 11), // user 11 played it last time
            self::assignment(5, 12), // user 12 only in a later project
        ];

        $marks = AssignmentHistory::marks($current, $projects, $assignments, [10, 11, 12, 13]);

        $this->assertSame([
            10 => HistoryMark::Before,
            11 => HistoryMark::LastTime,
            12 => HistoryMark::Never,
            13 => HistoryMark::Never,
        ], $marks);
    }

    public function testLastTimeIsTheMostRecentEarlierProjectWithThePiece(): void
    {
        $current = self::project(3, '2028-05-01');
        // The previous project with the piece is 2; user 10 played the part only in project 1.
        $marks = AssignmentHistory::marks(
            $current,
            [self::project(1, '2026-01-01'), self::project(2, '2027-01-01'), $current],
            [self::assignment(1, 10)],
            [10]
        );

        $this->assertSame([10 => HistoryMark::Before], $marks);
    }

    public function testIgnoresDraftsAndProjectsWithoutDates(): void
    {
        $current = self::project(3, '2028-05-01');
        $marks = AssignmentHistory::marks(
            $current,
            [self::project(1, '2027-01-01', ProjectStatus::Draft), self::project(2, null), $current],
            [self::assignment(1, 10), self::assignment(2, 11)],
            [10, 11]
        );

        $this->assertSame([10 => HistoryMark::Never, 11 => HistoryMark::Never], $marks);
    }

    public function testFallsBackToTheEndDate(): void
    {
        $current = new Project(3, 'Current', '', ProjectStatus::Open, '2028-01-01', '2028-02-01', null);
        $earlier = new Project(1, 'Earlier', '', ProjectStatus::Completed, '2027-01-01', '2027-02-01', null);

        $marks = AssignmentHistory::marks($current, [$earlier, $current], [self::assignment(1, 10)], [10]);

        $this->assertSame([10 => HistoryMark::LastTime], $marks);
    }

    private static function project(
        int $id,
        ?string $concertDate,
        ProjectStatus $status = ProjectStatus::Completed,
    ): Project {
        return new Project($id, 'Project ' . $id, '', $status, null, null, $concertDate);
    }

    private static function assignment(int $projectId, int $userId): Assignment
    {
        return new Assignment($projectId * 100 + $userId, $projectId, self::PIECE, self::PART, $userId);
    }
}
