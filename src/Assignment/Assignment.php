<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

final class Assignment
{
    public function __construct(
        public readonly int $id,
        public readonly int $projectId,
        public readonly int $pieceId,
        public readonly int $partId,
        /** null once the user has been deleted */
        public readonly ?int $userId,
    ) {
    }
}
