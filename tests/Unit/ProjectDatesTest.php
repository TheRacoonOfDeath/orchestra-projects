<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Project\ProjectDates;
use PHPUnit\Framework\TestCase;

final class ProjectDatesTest extends TestCase
{
    public function testAcceptsValidAndEmptyDates(): void
    {
        $dates = ProjectDates::fromInput('2027-03-01', ' 2027-05-10 ', []);

        $this->assertSame([], $dates->errors);
        $this->assertSame('2027-03-01', $dates->startDate);
        $this->assertSame('2027-05-10', $dates->endDate);
        $this->assertSame([], $dates->concertDates);
    }

    public function testRejectsInvalidDates(): void
    {
        $dates = ProjectDates::fromInput('2027-02-30', 'tomorrow', ['2027-01-01', '2027-13-01', ['2027-01-02']]);

        $this->assertSame([
            'Start date is not a valid date.',
            'End date is not a valid date.',
            'Concert date is not a valid date.',
        ], $dates->errors);
        $this->assertNull($dates->startDate);
        $this->assertSame(['2027-01-01'], $dates->concertDates);
    }

    public function testTakesAnyNumberOfConcertsInOrder(): void
    {
        $dates = ProjectDates::fromInput('', '', ['2027-05-09', '', ' 2027-05-08 ', '2027-05-09']);

        $this->assertSame([], $dates->errors);
        $this->assertSame(['2027-05-08', '2027-05-09'], $dates->concertDates);
        $this->assertSame([], ProjectDates::fromInput('', '', '')->concertDates);
        $this->assertSame(['2027-05-09'], ProjectDates::fromInput('', '', '2027-05-09')->concertDates);
    }

    public function testRejectsAnEndBeforeTheStart(): void
    {
        $dates = ProjectDates::fromInput('2027-05-10', '2027-03-01', ['2027-05-09']);

        $this->assertSame(['The end date must not be before the start date.'], $dates->errors);
    }
}
