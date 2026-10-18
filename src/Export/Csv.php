<?php

declare(strict_types=1);

namespace Orchestra\Projects\Export;

/**
 * CSV files that Excel opens by double-click without an import dialog: UTF-8 with a byte order
 * mark (otherwise Excel reads umlauts as ANSI), every cell quoted, Windows line endings and the
 * column separator Excel expects in the user's language.
 */
final class Csv
{
    private const BYTE_ORDER_MARK = "\u{FEFF}";

    private const SEPARATORS = [',', ';', "\t"];

    /** Excel runs cells starting with these as formulas. */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t"];

    /**
     * The column separator for the Orchestra language. Excel uses the list separator of the
     * Windows region: "," in English, ";" where the comma is the decimal separator, e.g. German.
     */
    public static function separator(): string
    {
        /* translators: Column separator of exported CSV files. Use the list separator of Excel in
           your language, e.g. ";" for German. */
        $separator = \_x(',', 'CSV column separator', 'orchestra-projects');

        return \in_array($separator, self::SEPARATORS, true) ? $separator : ',';
    }

    /**
     * @param list<list<string>> $rows
     */
    public static function document(array $rows, string $separator): string
    {
        $lines = \array_map(
            static fn (array $row): string => \implode($separator, \array_map([self::class, 'cell'], $row)),
            $rows
        );

        return self::BYTE_ORDER_MARK . \implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Quotes the value and keeps Excel from running it as a formula, since profile values are
     * entered by the members.
     */
    private static function cell(string $value): string
    {
        $value = \str_replace(["\r\n", "\r"], "\n", $value);
        if ($value !== '' && \in_array($value[0], self::FORMULA_PREFIXES, true)) {
            $value = "'" . $value;
        }

        return '"' . \str_replace('"', '""', $value) . '"';
    }
}
