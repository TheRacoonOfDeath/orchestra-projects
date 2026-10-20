<?php

declare(strict_types=1);

namespace Orchestra\Projects\Participation;

final class Participation
{
    public function __construct(
        public readonly int $id,
        public readonly int $projectId,
        /** null once the user has been deleted */
        public readonly ?int $userId,
        public readonly ParticipationStatus $status,
        /** When the member responded themselves (UTC, Y-m-d H:i:s) */
        public readonly ?string $respondedAt,
        /** Who changed the status last: the member or an organizer; null if nobody or deleted */
        public readonly ?int $changedBy = null,
        /** When the status was changed last (UTC, Y-m-d H:i:s) */
        public readonly ?string $changedAt = null,
    ) {
    }
}
