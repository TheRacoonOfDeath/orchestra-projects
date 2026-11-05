<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Assignment\Assignment;
use Orchestra\Projects\Assignment\AssignmentChanges;
use Orchestra\Projects\Assignment\PartChange;
use PHPUnit\Framework\TestCase;

final class AssignmentChangesTest extends TestCase
{
    public function testFindsNewChangedAndRemovedParts(): void
    {
        $changes = AssignmentChanges::between(
            [7 => [1 => 11, 2 => 21], 8 => [1 => 12]],
            [7 => [1 => 13, 3 => 31], 8 => [1 => 12]]
        );

        $this->assertEquals([
            new PartChange(7, 1, 11, 13),
            new PartChange(7, 2, 21, null),
            new PartChange(7, 3, null, 31),
        ], $changes);
    }

    public function testAPartChangedBackIsNoChange(): void
    {
        $this->assertSame([], AssignmentChanges::between([7 => [1 => 11]], [7 => [1 => 11]]));
    }

    public function testMembersWhoLeftTheProjectLoseTheirParts(): void
    {
        $this->assertEquals(
            [new PartChange(9, 1, 11, null), new PartChange(9, 2, 21, null)],
            AssignmentChanges::between([9 => [1 => 11, 2 => 21]], [])
        );
    }

    public function testMapsAssignmentsWithoutDeletedUsers(): void
    {
        $this->assertSame(
            [7 => [1 => 11, 2 => 21]],
            AssignmentChanges::map([
                new Assignment(1, 5, 1, 11, 7),
                new Assignment(2, 5, 2, 21, 7),
                new Assignment(3, 5, 1, 12, null),
            ])
        );
    }

    public function testTheFingerprintChangesWithTheChanges(): void
    {
        $one = [new PartChange(7, 1, 11, 13)];

        $this->assertSame(AssignmentChanges::fingerprint($one), AssignmentChanges::fingerprint($one));
        $this->assertNotSame(
            AssignmentChanges::fingerprint($one),
            AssignmentChanges::fingerprint([new PartChange(7, 1, 11, 14)])
        );
    }

    public function testGroupsTheChangesByMember(): void
    {
        $changes = [new PartChange(7, 1, 11, 13), new PartChange(8, 1, null, 12), new PartChange(7, 2, 21, null)];

        $this->assertSame([7, 8], \array_keys(AssignmentChanges::byUser($changes)));
        $this->assertCount(2, AssignmentChanges::byUser($changes)[7]);
    }
}
