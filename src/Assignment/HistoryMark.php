<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

enum HistoryMark: string
{
    case LastTime = 'last_time';
    case Completed = 'completed';
    case Never = 'never';

    public function label(): string
    {
        return match ($this) {
            self::LastTime => \__('played last time', 'orchestra-projects'),
            self::Completed => \__('played in a completed project', 'orchestra-projects'),
            self::Never => \__('never played', 'orchestra-projects'),
        };
    }
}
