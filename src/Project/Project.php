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
        /** @var list<string> Y-m-d, in chronological order; projects may have no concert */
        public readonly array $concertDates = [],
    ) {
    }

    /**
     * The date that orders projects in time, e.g. in project lists: the last concert,
     * or the end or start date if there is none.
     */
    public function sortDate(): ?string
    {
        $lastConcert = $this->concertDates[\array_key_last($this->concertDates) ?? -1] ?? null;

        return $lastConcert ?? $this->endDate ?? $this->startDate;
    }
}
