<?php

declare(strict_types=1);

namespace Orchestra\Projects\Tests\Unit;

use Orchestra\Library\Piece\Piece;
use Orchestra\Projects\Program\ProgramDuration;
use PHPUnit\Framework\TestCase;

final class ProgramDurationTest extends TestCase
{
    public function testAddsUpTheKnownDurationsAndCountsTheMissingOnes(): void
    {
        $total = ProgramDuration::of([$this->piece(1, 245), $this->piece(2, null), $this->piece(3, 3600)]);

        $this->assertSame(3845, $total->seconds);
        $this->assertSame(1, $total->missing);
        $this->assertTrue($total->known());
    }

    public function testNothingToShowWithoutDurations(): void
    {
        $this->assertFalse(ProgramDuration::of([])->known());

        $total = ProgramDuration::of([$this->piece(1, null), $this->piece(2, null)]);
        $this->assertFalse($total->known());
        $this->assertSame(2, $total->missing);
    }

    private function piece(int $id, ?int $duration): Piece
    {
        return new Piece($id, 'Piece ' . $id, '', '', false, true, $duration);
    }
}
