<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

final class Project
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $description,
        public readonly ProjectStatus $status,
        /** Y-m-d or null */
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly ?string $concertDate,
    ) {
    }

    /**
     * The date that orders projects in time, e.g. for the assignment history: the concert date,
     * or the end or start date if there is none.
     */
    public function sortDate(): ?string
    {
        return $this->concertDate ?? $this->endDate ?? $this->startDate;
    }
}
