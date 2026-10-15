<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Projects\Project\Project;

final class Dates
{
    public static function range(Project $project): string
    {
        if ($project->startDate === null && $project->endDate === null) {
            return '—';
        }

        return self::format($project->startDate) . ' – ' . self::format($project->endDate);
    }

    public static function format(?string $date): string
    {
        if ($date === null) {
            return '—';
        }
        $timestamp = \strtotime($date . ' 12:00:00');
        $format = \get_option('date_format');

        return $timestamp === false
            ? $date
            : \date_i18n(\is_string($format) && $format !== '' ? $format : 'Y-m-d', $timestamp);
    }
}
