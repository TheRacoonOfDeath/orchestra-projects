<?php

declare(strict_types=1);

namespace Orchestra\Projects\Field;

/**
 * Reads the form in which organizers change the project fields.
 *
 * Existing fields are submitted as `orchestra_project_fields[ID][...]` with `label`, `hint`,
 * `choices`, `other` and `required`, new ones as `orchestra_new_project_fields[INDEX][...]` with a
 * `type` in addition. `other` (free-text answer, choice lists) and `required` are "1" if set. The
 * order is the order of the rows: each row submits its key (the field ID, or "new:INDEX") in
 * `orchestra_project_field_order[]`. The delete button of a field submits its ID as `orchestra_delete_project_field`.
 *
 * The type of an existing field cannot be changed, so stored answers always match their field. New
 * rows without a label are ignored.
 */
final class ProjectFieldsInput
{
    public const FIELD_FIELDS = 'orchestra_project_fields';
    public const FIELD_NEW_FIELDS = 'orchestra_new_project_fields';
    public const FIELD_ORDER = 'orchestra_project_field_order';
    public const FIELD_DELETE = 'orchestra_delete_project_field';
    public const NEW_KEY_PREFIX = 'new:';

    /**
     * @param list<ProjectField> $fields The fields to save, in display order
     * @param list<ProjectField> $removed Fields to delete, including their answers
     * @param list<string> $errors If not empty, nothing may be saved
     */
    private function __construct(
        public readonly array $fields,
        public readonly array $removed,
        public readonly array $errors,
    ) {
    }

    /**
     * @param array<mixed> $post Unslashed form data
     * @param list<ProjectField> $current
     */
    public static function fromForm(array $post, array $current): self
    {
        $submitted = \is_array($post[self::FIELD_FIELDS] ?? null) ? $post[self::FIELD_FIELDS] : [];
        $new = \is_array($post[self::FIELD_NEW_FIELDS] ?? null) ? $post[self::FIELD_NEW_FIELDS] : [];
        $order = \is_array($post[self::FIELD_ORDER] ?? null)
            ? \array_flip(\array_values(\array_filter($post[self::FIELD_ORDER], 'is_string')))
            : [];
        $delete = $post[self::FIELD_DELETE] ?? null;
        // Rows missing from the order keep their place after the ordered ones.
        $unordered = \count($order);

        $errors = [];
        $removed = [];
        /** @var list<array{int, ProjectField}> $positioned */
        $positioned = [];
        $taken = [];
        foreach ($current as $field) {
            $taken[] = $field->id;
            $position = $order[$field->id] ?? $unordered++;
            if ($delete === $field->id) {
                $removed[] = $field;
                continue;
            }
            $row = $submitted[$field->id] ?? null;
            if (!\is_array($row)) {
                $positioned[] = [$position, $field];
                continue;
            }

            $label = self::text($row, 'label');
            [$choices, $choiceErrors] = self::choices($field->type, $label, $row);
            $errors = [...$errors, ...self::labelErrors($label, self::text($row, 'hint')), ...$choiceErrors];
            $positioned[] = [$position, self::field($field->id, $label, $field->type, $row, $choices)];
        }

        foreach ($new as $key => $row) {
            if (!\is_array($row) || self::text($row, 'label') === '') {
                continue;
            }
            $label = self::text($row, 'label');
            $type = $row['type'] ?? null;
            if (!\is_string($type) || !\in_array($type, ProjectField::TYPES, true)) {
                /* translators: %s: field label */
                $errors[] = \sprintf(\__('Please choose a type for "%s".', 'orchestra-projects'), $label);
                continue;
            }

            [$choices, $choiceErrors] = self::choices($type, $label, $row);
            $errors = [...$errors, ...self::labelErrors($label, self::text($row, 'hint')), ...$choiceErrors];
            $id = ProjectFields::newId($label, $taken);
            $taken[] = $id;
            $positioned[] = [
                $order[self::NEW_KEY_PREFIX . $key] ?? $unordered++,
                self::field($id, $label, $type, $row, $choices),
            ];
        }

        \usort($positioned, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $fields = \array_map(static fn (array $entry): ProjectField => $entry[1], $positioned);

        return new self($fields, $removed, [...$errors, ...self::duplicateErrors($fields)]);
    }

    /**
     * @param array<mixed> $row
     * @param list<string> $choices
     */
    private static function field(string $id, string $label, string $type, array $row, array $choices): ProjectField
    {
        return new ProjectField(
            $id,
            $label,
            $type,
            self::text($row, 'hint'),
            $choices,
            $type === ProjectField::TYPE_SELECT && ($row['other'] ?? null) === '1',
            ($row['required'] ?? null) === '1',
        );
    }

    /**
     * @return list<string>
     */
    private static function labelErrors(string $label, string $hint): array
    {
        $errors = [];
        if ($label === '') {
            $errors[] = \__('Every field needs a label.', 'orchestra-projects');
        } elseif (\mb_strlen($label) > ProjectField::LABEL_MAX_LENGTH) {
            /* translators: 1: field label, 2: maximum number of characters */
            $message = \__('The label "%1$s" is longer than %2$d characters.', 'orchestra-projects');
            $errors[] = \sprintf($message, $label, ProjectField::LABEL_MAX_LENGTH);
        }
        if (\mb_strlen($hint) > ProjectField::HINT_MAX_LENGTH) {
            /* translators: 1: field label, 2: maximum number of characters */
            $message = \__('The hint of "%1$s" is longer than %2$d characters.', 'orchestra-projects');
            $errors[] = \sprintf($message, $label, ProjectField::HINT_MAX_LENGTH);
        }

        return $errors;
    }

    /**
     * The choices of a choice list, one per line, without empty lines and duplicates.
     *
     * @param array<mixed> $row
     * @return array{list<string>, list<string>} choices and errors
     */
    private static function choices(string $type, string $label, array $row): array
    {
        if ($type !== ProjectField::TYPE_SELECT) {
            return [[], []];
        }

        $lines = \explode("\n", Text::multiLine(\is_string($row['choices'] ?? null) ? $row['choices'] : ''));
        $choices = \array_values(\array_unique(\array_filter(
            \array_map(static fn (string $line): string => Text::singleLine($line), $lines),
            static fn (string $choice): bool => $choice !== ''
        )));

        $errors = [];
        if ($choices === []) {
            /* translators: %s: field label */
            $errors[] = \sprintf(\__('Please enter the choices for "%s", one per line.', 'orchestra-projects'), $label);
        }
        foreach ($choices as $choice) {
            if (\mb_strlen($choice) > ProjectField::CHOICE_MAX_LENGTH) {
                /* translators: 1: choice, 2: maximum number of characters */
                $message = \__('The choice "%1$s" is longer than %2$d characters.', 'orchestra-projects');
                $errors[] = \sprintf($message, $choice, ProjectField::CHOICE_MAX_LENGTH);
            }
        }

        return [$choices, $errors];
    }

    /**
     * @param list<ProjectField> $fields
     * @return list<string>
     */
    private static function duplicateErrors(array $fields): array
    {
        $errors = [];
        $seen = [];
        foreach ($fields as $field) {
            $key = \mb_strtolower($field->label);
            if ($key !== '' && isset($seen[$key])) {
                /* translators: %s: field label */
                $message = \__('The label "%s" is used more than once.', 'orchestra-projects');
                $errors[] = \sprintf($message, $field->label);
            }
            $seen[$key] = true;
        }

        return \array_values(\array_unique($errors));
    }

    /**
     * @param array<mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        return \is_string($row[$key] ?? null) ? Text::singleLine($row[$key]) : '';
    }
}
