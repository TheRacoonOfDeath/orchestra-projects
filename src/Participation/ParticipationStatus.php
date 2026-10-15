<?php

declare(strict_types=1);

namespace Orchestra\Projects\Participation;

enum ParticipationStatus: string
{
    /** No response yet ("pending"). */
    case Open = 'open';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Open => \__('Pending', 'orchestra-projects'),
            self::Accepted => \__('Accepted', 'orchestra-projects'),
            self::Declined => \__('Declined', 'orchestra-projects'),
        };
    }
}
