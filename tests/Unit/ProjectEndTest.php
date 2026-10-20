<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class ProjectEndTest extends TestCase
{
    public function testTheLastDayIsTheLatestOfEndDateConcertsAndStartDate(): void
    {
        $this->assertSame('2026-10-12', self::project(ProjectStatus::Open, '2026-10-08', '2026-10-10', ['2026-10-12'])
            ->lastDay());
        $this->assertSame('2026-10-08', self::project(ProjectStatus::Open, '2026-10-08', null)->lastDay());
        $this->assertNull(self::project(ProjectStatus::Open, null, null)->lastDay());
    }

    public function testAProjectEndsTheDayAfterItsLastDay(): void
    {
        $project = self::project(ProjectStatus::Open, '2026-10-08', '2026-10-10');

        $this->assertFalse($project->endedBefore('2026-10-10'));
        $this->assertTrue($project->endedBefore('2026-10-11'));
        $this->assertFalse(self::project(ProjectStatus::Open, null, null)->endedBefore('2030-01-01'));
    }

    public function testOnlyOpenAndPreparingProjectsWaitForCompletion(): void
    {
        foreach (ProjectStatus::cases() as $status) {
            $waits = self::project($status, '2026-10-08', '2026-10-10')->waitsForCompletion('2026-10-20');

            $this->assertSame(\in_array($status, [ProjectStatus::Open, ProjectStatus::Preparation], true), $waits);
        }
    }

    /**
     * @param list<string> $concerts
     */
    private static function project(ProjectStatus $status, ?string $start, ?string $end, array $concerts = []): Project
    {
        return new Project(1, 'Speyer', '', $status, $start, $end, $concerts);
    }
}
