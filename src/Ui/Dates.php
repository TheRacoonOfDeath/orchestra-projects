<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Projects\Project\Project;

final class Dates
{
    /** The date format WordPress sets on installation in English; it reads wrong in other languages. */
    private const ENGLISH_DEFAULT_FORMAT = 'F j, Y';

    public static function range(Project $project): string
    {
        if ($project->startDate === null && $project->endDate === null) {
            return '—';
        }

        return self::format($project->startDate) . ' – ' . self::format($project->endDate);
    }

    /**
     * The concert dates of a project, e.g. "May 8, 2027, May 9, 2027"; "" if it has none.
     */
    public static function concerts(Project $project): string
    {
        return \implode(', ', \array_map([self::class, 'format'], $project->concertDates));
    }

    /**
     * The label for {@see concerts()}: "Concert" or "Concerts".
     */
    public static function concertsLabel(Project $project): string
    {
        return \_n('Concert', 'Concerts', \count($project->concertDates), 'orchestra-projects');
    }

    /**
     * Today in the site's time zone (Y-m-d).
     */
    public static function today(): string
    {
        return \current_time('Y-m-d');
    }

    /**
     * A date (Y-m-d) in the site's date format, with translated month names.
     */
    public static function format(?string $date): string
    {
        if ($date === null) {
            return '—';
        }
        $timestamp = \strtotime($date . ' 12:00:00 UTC');

        // A date has no time zone: format it as it is, without the site's offset.
        return $timestamp === false
            ? $date
            : (string) \wp_date(self::dateFormat(), $timestamp, new \DateTimeZone('UTC'));
    }

    /**
     * A point in time stored in UTC (Y-m-d H:i:s), as a date in the site's time zone.
     */
    public static function fromGmt(string $dateTime): string
    {
        $timestamp = \strtotime($dateTime . ' UTC');

        return $timestamp === false ? $dateTime : (string) \wp_date(self::dateFormat(), $timestamp);
    }

    /**
     * The site's date format. If it is still WordPress's English default, the default of the
     * language the Orchestra pages are shown in, e.g. "8. Oktober 2026" in German.
     */
    private static function dateFormat(): string
    {
        $format = \get_option('date_format');
        if (!\is_string($format) || $format === '') {
            return 'Y-m-d';
        }

        /* translators: date format, see https://www.php.net/manual/datetime.format.php; German: j. F Y */
        return $format === self::ENGLISH_DEFAULT_FORMAT ? \__('F j, Y', 'orchestra-projects') : $format;
    }
}
