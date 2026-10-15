<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

enum HistoryMark: string
{
    case LastTime = 'last_time';
    case Before = 'before';
    case Never = 'never';

    public function label(): string
    {
        return match ($this) {
            self::LastTime => \__('played last time', 'orchestra-projects'),
            self::Before => \__('played before', 'orchestra-projects'),
            self::Never => \__('never played', 'orchestra-projects'),
        };
    }
}
