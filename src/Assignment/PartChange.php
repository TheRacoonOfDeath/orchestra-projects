<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

/**
 * A member's part in a piece that changed since they were last informed: a new part (no old one),
 * another part, or no part any more (no new one).
 */
final class PartChange
{
    public function __construct(
        public readonly int $userId,
        public readonly int $pieceId,
        public readonly ?int $oldPartId,
        public readonly ?int $newPartId,
    ) {
    }
}
