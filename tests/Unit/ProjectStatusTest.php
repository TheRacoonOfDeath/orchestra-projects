<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class ProjectStatusTest extends TestCase
{
    public function testMovesOneStepForwardOrBack(): void
    {
        $this->assertTrue(ProjectStatus::Draft->canMoveTo(ProjectStatus::Open));
        $this->assertTrue(ProjectStatus::Preparation->canMoveTo(ProjectStatus::Open));
        $this->assertFalse(ProjectStatus::Draft->canMoveTo(ProjectStatus::Preparation));
        $this->assertFalse(ProjectStatus::Open->canMoveTo(ProjectStatus::Open));
        $this->assertNull(ProjectStatus::Completed->next());
        $this->assertNull(ProjectStatus::Draft->previous());
    }

    public function testFollowsTheStatusTableOfTheDomainModel(): void
    {
        $table = [
            // visible, respond, assignments
            'draft' => [false, false, false],
            'open' => [true, true, true],
            'preparation' => [true, false, true],
            'completed' => [true, false, false],
        ];

        foreach ($table as $value => [$visible, $respond, $assignments]) {
            $status = ProjectStatus::from($value);
            $this->assertSame($visible, $status->visibleToMembers(), $value);
            $this->assertSame($respond, $status->membersCanRespond(), $value);
            $this->assertSame($assignments, $status->assignmentsEditable(), $value);
        }
    }

    public function testMembersSeeOnlyRunningProjectsAsActive(): void
    {
        $current = \array_filter(ProjectStatus::cases(), static fn (ProjectStatus $s): bool => $s->current());
        $active = \array_filter(ProjectStatus::cases(), static fn (ProjectStatus $s): bool => $s->active());

        $this->assertSame([ProjectStatus::Open, ProjectStatus::Preparation], \array_values($current));
        $this->assertSame(
            [ProjectStatus::Draft, ProjectStatus::Open, ProjectStatus::Preparation],
            \array_values($active)
        );
    }

    public function testProjectsArchivedByEarlierVersionsAreCompleted(): void
    {
        $this->assertSame(ProjectStatus::Completed, ProjectStatus::fromStored('archived'));
        $this->assertSame(ProjectStatus::Open, ProjectStatus::fromStored('open'));
        $this->assertSame(ProjectStatus::Draft, ProjectStatus::fromStored(null));
        $this->assertSame(ProjectStatus::Draft, ProjectStatus::fromStored('unknown'));
    }
}
