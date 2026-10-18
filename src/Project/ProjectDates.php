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
        /** @var list<string> in chronological order, without duplicates */
        public readonly array $concertDates,
        /** @var list<string> */
        public readonly array $errors,
    ) {
    }

    /**
     * @param mixed $concerts The concert dates; empty fields are ignored
     */
    public static function fromInput(mixed $start, mixed $end, mixed $concerts): self
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
        $concertDates = [];
        foreach (\is_array($concerts) ? $concerts : [$concerts] as $concert) {
            $concertDates[] = $parse($concert, \__('Concert date', 'orchestra-projects'));
        }
        $concertDates = \array_values(\array_unique(\array_filter($concertDates, \is_string(...))));
        \sort($concertDates);

        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            $errors[] = \__('The end date must not be before the start date.', 'orchestra-projects');
        }

        return new self($startDate, $endDate, $concertDates, $errors);
    }
}
