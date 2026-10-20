<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Projects\Assignment\Assignment;
use Orchestra\Projects\Assignment\AssignmentMatrix;
use PHPUnit\Framework\TestCase;

final class AssignmentMatrixTest extends TestCase
{
    public function testOneRowPerMemberAndOneCellPerPiece(): void
    {
        $assignments = [
            new Assignment(1, 9, 50, 500, 10),
            new Assignment(2, 9, 60, 601, 10),
            new Assignment(3, 9, 60, 600, 11),
            new Assignment(4, 9, 50, 501, 12), // assigned, but no longer accepted
            new Assignment(5, 9, 50, 500, null), // deleted user: left out
        ];

        $rows = AssignmentMatrix::rows(
            [11 => 'Anna', 10 => 'Mia'],
            [50, 60],
            $assignments,
            [500 => 'Horn 1', 501 => 'Horn 2', 600 => 'Violin 1', 601 => 'Violin 2'],
            [2 => true],
            static fn (int $userId): string => 'User ' . $userId
        );

        $this->assertSame([
            ['name' => 'Anna', 'cells' => [null, ['part' => 'Violin 1', 'new' => false]]],
            [
                'name' => 'Mia',
                'cells' => [['part' => 'Horn 1', 'new' => false], ['part' => 'Violin 2', 'new' => true]],
            ],
            ['name' => 'User 12', 'cells' => [['part' => 'Horn 2', 'new' => false], null]],
        ], $rows);
    }
}
