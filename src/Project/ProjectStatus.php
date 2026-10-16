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

    /**
     * The status saved for a project. Projects archived by versions before 0.8 count as completed.
     */
    public static function fromStored(?string $value): self
    {
        return $value === 'archived' ? self::Completed : (self::tryFrom($value ?? '') ?? self::Draft);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => \__('Draft', 'orchestra-projects'),
            self::Open => \__('Open for Participation', 'orchestra-projects'),
            self::Preparation => \__('Preparation', 'orchestra-projects'),
            self::Completed => \__('Completed', 'orchestra-projects'),
        };
    }

    public function visibleToMembers(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Shown to members in the active projects and offered as email groups. Completed projects are
     * listed separately and can still be opened.
     */
    public function current(): bool
    {
        return $this === self::Open || $this === self::Preparation;
    }

    /** In the list of active projects, including drafts for conductors and organizers. */
    public function active(): bool
    {
        return $this !== self::Completed;
    }

    public function membersCanRespond(): bool
    {
        return $this === self::Open;
    }

    public function assignmentsEditable(): bool
    {
        return $this === self::Open || $this === self::Preparation;
    }

    /** Completed programs are history and stay unchanged. */
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
            self::Completed => null,
        };
    }

    public function previous(): ?self
    {
        return match ($this) {
            self::Draft => null,
            self::Open => self::Draft,
            self::Preparation => self::Open,
            self::Completed => self::Preparation,
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
