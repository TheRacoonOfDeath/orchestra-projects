<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Project\ProjectDeletion;
use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class ProjectDeletionTest extends TestCase
{
    public function testOnlyDraftsThatWereNeverOpenedCountAsUnopened(): void
    {
        $this->assertTrue(ProjectDeletion::neverOpened(ProjectStatus::Draft, null, false));
        // Moved back to Draft after opening: the day of the opening or the participation records remain.
        $this->assertFalse(ProjectDeletion::neverOpened(ProjectStatus::Draft, '2027-01-10', false));
        $this->assertFalse(ProjectDeletion::neverOpened(ProjectStatus::Draft, null, true));
        foreach ([ProjectStatus::Open, ProjectStatus::Preparation, ProjectStatus::Completed] as $status) {
            $this->assertFalse(ProjectDeletion::neverOpened($status, null, false));
        }
    }
}
