<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Email\ProjectEmailGroups;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class ProjectEmailGroupsTest extends TestCase
{
    public function testTheNextProjectComesFirstAndProjectsWithoutDatesLast(): void
    {
        $projects = [
            new Project(15, 'Tour', '', ProjectStatus::Open, '2026-10-23', '2026-11-01'),
            new Project(30, 'Unplanned', '', ProjectStatus::Preparation, null, null),
            new Project(27, 'Speyer', '', ProjectStatus::Open, '2026-10-08', '2026-10-10', ['2026-12-01']),
        ];

        $this->assertSame(
            [27, 15, 30],
            \array_map(static fn (Project $project): int => $project->id, ProjectEmailGroups::byStartDate($projects))
        );
    }
}
