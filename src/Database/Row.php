<?php

declare(strict_types=1);

namespace Orchestra\Projects\Database;

use UnexpectedValueException;

/**
 * Typed access to rows returned by `$wpdb`, which are untyped arrays of strings.
 */
final class Row
{
    /**
     * @return list<array<mixed>>
     */
    public static function all(mixed $results): array
    {
        if (!\is_array($results)) {
            return [];
        }

        return \array_values(\array_filter($results, \is_array(...)));
    }

    /**
     * A comma-separated list of IDs for `IN (…)`, safe to embed in SQL because every value is cast to int.
     *
     * @param list<int> $ids Must not be empty
     */
    public static function idList(array $ids): string
    {
        return \implode(',', \array_map('intval', $ids));
    }

    /**
     * @param array<mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && \is_numeric($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException(\sprintf('Column "%s" is not an integer.', $column));
    }

    /**
     * @param array<mixed> $row
     */
    public static function nullableInt(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /**
     * @param array<mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value)) {
            return (string) $value;
        }

        throw new UnexpectedValueException(\sprintf('Column "%s" is not a string.', $column));
    }
}
