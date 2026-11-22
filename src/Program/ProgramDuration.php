<?php

declare(strict_types=1);

namespace Orchestra\Projects\Program;

use Orchestra\Library\Piece\Piece;

/**
 * The total duration of a program: the sum of the known durations and how many pieces have none.
 */
final class ProgramDuration
{
    private function __construct(public readonly int $seconds, public readonly int $missing)
    {
    }

    /**
     * @param list<Piece> $pieces
     */
    public static function of(array $pieces): self
    {
        $seconds = 0;
        $missing = 0;
        foreach ($pieces as $piece) {
            if ($piece->duration === null) {
                $missing++;
            } else {
                $seconds += $piece->duration;
            }
        }

        return new self($seconds, $missing);
    }

    /**
     * Whether there is a total to show: at least one piece has a duration.
     */
    public function known(): bool
    {
        return $this->seconds > 0;
    }
}
