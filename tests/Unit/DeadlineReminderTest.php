<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Email\DeadlineReminder;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;
use PHPUnit\Framework\TestCase;

final class DeadlineReminderTest extends TestCase
{
    public function testTheReminderIsDueFromSevenDaysBeforeTheDeadlineUntilTheDeadline(): void
    {
        $project = self::project('2027-03-15');

        $this->assertSame('2027-03-08', DeadlineReminder::reminderDay('2027-03-15'));
        $this->assertFalse(DeadlineReminder::due($project, '2027-01-01', '2027-01-01', null, '2027-03-07'));
        $this->assertTrue(DeadlineReminder::due($project, '2027-01-01', '2027-01-01', null, '2027-03-08'));
        $this->assertTrue(
            DeadlineReminder::due($project, '2027-01-01', '2027-01-01', null, '2027-03-15'),
            'a late WP-Cron still sends it on the day of the deadline'
        );
        $this->assertFalse(DeadlineReminder::due($project, '2027-01-01', '2027-01-01', null, '2027-03-16'));
    }

    public function testTheReminderIsSentOncePerDeadline(): void
    {
        $this->assertFalse(
            DeadlineReminder::due(self::project('2027-03-15'), null, null, '2027-03-15', '2027-03-10')
        );
        $this->assertTrue(
            DeadlineReminder::due(self::project('2027-03-20'), null, '2027-03-01', '2027-03-15', '2027-03-13'),
            'a deadline moved early enough gets its own reminder'
        );
    }

    public function testNoReminderWhenTheProjectWasOpenedOrTheDeadlineSetWithLessThanSevenDaysLeft(): void
    {
        $project = self::project('2027-03-15');

        $this->assertFalse(DeadlineReminder::due($project, '2027-03-09', '2027-01-01', null, '2027-03-10'));
        $this->assertFalse(DeadlineReminder::due($project, '2027-01-01', '2027-03-09', null, '2027-03-10'));
        $this->assertTrue(
            DeadlineReminder::due($project, '2027-03-08', '2027-03-08', null, '2027-03-10'),
            'opened on the day of the reminder still counts'
        );
        $this->assertTrue(
            DeadlineReminder::due($project, null, '2027-01-01', null, '2027-03-10'),
            'projects opened before the opening day was stored count as open'
        );
    }

    public function testOnlyOpenProjectsWithADeadlineGetAReminder(): void
    {
        $this->assertFalse(DeadlineReminder::due(self::project(null), null, null, null, '2027-03-10'));
        $this->assertFalse(DeadlineReminder::due(
            self::project('2027-03-15', ProjectStatus::Preparation),
            null,
            null,
            null,
            '2027-03-10'
        ));
    }

    public function testTheLastChanceIsDueOnlyOnTheDeadlineDay(): void
    {
        $project = self::project('2027-03-15');
        $this->assertFalse(DeadlineReminder::lastChanceDue($project, '2027-01-01', '2027-01-01', null, '2027-03-14'));
        $this->assertTrue(DeadlineReminder::lastChanceDue($project, '2027-01-01', '2027-01-01', null, '2027-03-15'));
        $this->assertFalse(DeadlineReminder::lastChanceDue($project, '2027-01-01', '2027-01-01', null, '2027-03-16'));
    }

    public function testTheLastChanceIsSentOncePerDeadline(): void
    {
        $this->assertFalse(
            DeadlineReminder::lastChanceDue(self::project('2027-03-15'), null, null, '2027-03-15', '2027-03-15')
        );
        $this->assertTrue(
            DeadlineReminder::lastChanceDue(self::project('2027-03-20'), null, null, '2027-03-15', '2027-03-20'),
            'a new deadline gets its own last chance'
        );
    }

    public function testTheLastChanceNeedsTheProjectOpenAndTheDeadlineSetTheDayBefore(): void
    {
        $project = self::project('2027-03-15');
        $this->assertFalse(DeadlineReminder::lastChanceDue($project, '2027-03-15', '2027-01-01', null, '2027-03-15'));
        $this->assertFalse(DeadlineReminder::lastChanceDue($project, '2027-01-01', '2027-03-15', null, '2027-03-15'));
        $this->assertTrue(
            DeadlineReminder::lastChanceDue($project, '2027-03-14', '2027-03-14', null, '2027-03-15'),
            'without the seven-day reminder, the last chance still comes'
        );
    }

    public function testOnlyOpenProjectsWithADeadlineGetALastChance(): void
    {
        $this->assertFalse(DeadlineReminder::lastChanceDue(self::project(null), null, null, null, '2027-03-15'));
        $this->assertFalse(DeadlineReminder::lastChanceDue(
            self::project('2027-03-15', ProjectStatus::Preparation),
            null,
            null,
            null,
            '2027-03-15'
        ));
    }

    private static function project(?string $deadline, ProjectStatus $status = ProjectStatus::Open): Project
    {
        return new Project(1, 'Spring Concert', '', $status, '2027-04-01', '2027-04-10', [], $deadline);
    }
}
