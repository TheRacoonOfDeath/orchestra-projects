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

    /**
     * The last day of the project: the latest of its end date, its concerts and its start date.
     */
    public function lastDay(): ?string
    {
        $days = \array_filter([$this->startDate, $this->endDate, ...$this->concertDates], \is_string(...));

        return $days === [] ? null : \max($days);
    }

    /**
     * Whether the project is over on the given day (Y-m-d); projects without dates never are.
     */
    public function endedBefore(string $today): bool
    {
        $lastDay = $this->lastDay();

        return $lastDay !== null && $lastDay < $today;
    }

    /**
     * Whether organizers should be told to complete the project: it is over, but still open or in
     * preparation. Drafts are left alone, because opening them would ask the members to respond.
     */
    public function waitsForCompletion(string $today): bool
    {
        return $this->endedBefore($today)
            && \in_array($this->status, [ProjectStatus::Open, ProjectStatus::Preparation], true);
    }
}
