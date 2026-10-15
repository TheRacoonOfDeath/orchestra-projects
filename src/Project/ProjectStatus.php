<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

/**
 * The lifecycle of a project. See the domain model in orchestra-core.
 */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Preparation = 'preparation';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => \__('Draft', 'orchestra-projects'),
            self::Open => \__('Open for Participation', 'orchestra-projects'),
            self::Preparation => \__('Preparation', 'orchestra-projects'),
            self::Completed => \__('Completed', 'orchestra-projects'),
            self::Archived => \__('Archived', 'orchestra-projects'),
        };
    }

    public function visibleToMembers(): bool
    {
        return $this !== self::Draft;
    }

    /** Shown on the member dashboard. Archived projects are still visible when opened directly. */
    public function current(): bool
    {
        return $this !== self::Draft && $this !== self::Archived;
    }

    public function membersCanRespond(): bool
    {
        return $this === self::Open;
    }

    public function assignmentsEditable(): bool
    {
        return $this === self::Open || $this === self::Preparation;
    }

    /** Completed and archived programs are history and stay unchanged. */
    public function programEditable(): bool
    {
        return $this === self::Draft || $this === self::Open || $this === self::Preparation;
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Draft => self::Open,
            self::Open => self::Preparation,
            self::Preparation => self::Completed,
            self::Completed => self::Archived,
            self::Archived => null,
        };
    }

    public function previous(): ?self
    {
        return match ($this) {
            self::Draft => null,
            self::Open => self::Draft,
            self::Preparation => self::Open,
            self::Completed => self::Preparation,
            self::Archived => self::Completed,
        };
    }

    /**
     * Projects move one step forward or back, e.g. back to Open for late responses.
     */
    public function canMoveTo(self $target): bool
    {
        return $target === $this->next() || $target === $this->previous();
    }
}
