<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

use DateTimeImmutable;

/**
 * Normalizes and validates the dates of the project form.
 */
final class ProjectDates
{
    public function __construct(
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly ?string $concertDate,
        /** @var list<string> */
        public readonly array $errors,
    ) {
    }

    public static function fromInput(mixed $start, mixed $end, mixed $concert): self
    {
        $errors = [];
        $parse = static function (mixed $value, string $label) use (&$errors): ?string {
            $value = \is_string($value) ? \trim($value) : '';
            if ($value === '') {
                return null;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                /* translators: %s: name of the date field, e.g. "Start date" */
                $errors[] = \sprintf(\__('%s is not a valid date.', 'orchestra-projects'), $label);
                return null;
            }

            return $value;
        };

        $startDate = $parse($start, \__('Start date', 'orchestra-projects'));
        $endDate = $parse($end, \__('End date', 'orchestra-projects'));
        $concertDate = $parse($concert, \__('Concert date', 'orchestra-projects'));

        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            $errors[] = \__('The end date must not be before the start date.', 'orchestra-projects');
        }

        return new self($startDate, $endDate, $concertDate, $errors);
    }
}
