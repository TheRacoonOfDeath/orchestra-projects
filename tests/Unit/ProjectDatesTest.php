<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Project\ProjectDates;
use PHPUnit\Framework\TestCase;

final class ProjectDatesTest extends TestCase
{
    public function testAcceptsValidAndEmptyDates(): void
    {
        $dates = ProjectDates::fromInput('2027-03-01', ' 2027-05-10 ', '');

        $this->assertSame([], $dates->errors);
        $this->assertSame('2027-03-01', $dates->startDate);
        $this->assertSame('2027-05-10', $dates->endDate);
        $this->assertNull($dates->concertDate);
    }

    public function testRejectsInvalidDates(): void
    {
        $dates = ProjectDates::fromInput('2027-02-30', 'tomorrow', ['2027-01-01']);

        $this->assertCount(2, $dates->errors);
        $this->assertNull($dates->startDate);
        $this->assertNull($dates->concertDate);
    }

    public function testRejectsAnEndBeforeTheStart(): void
    {
        $dates = ProjectDates::fromInput('2027-05-10', '2027-03-01', '2027-05-09');

        $this->assertSame(['The end date must not be before the start date.'], $dates->errors);
    }
}
