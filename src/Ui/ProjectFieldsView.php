<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Projects\Field\ProjectField;
use Orchestra\Projects\Field\ProjectFields;
use Orchestra\Projects\Field\ProjectFieldsInput;

/**
 * The form in which organizers define the project fields, the questions each project can ask its
 * participants. Shown by `[orchestra_projects]` (`?orchestra_project_fields=1`) and in wp-admin;
 * saved by {@see ProjectFieldsController}.
 */
final class ProjectFieldsView
{
    private const NEW_ROW_TEMPLATE = 'orchestra-projects-new-field';
    private const ROWS = 'orchestra-projects-field-rows';

    public function __construct(private readonly ProjectFields $fields)
    {
    }

    public function form(): string
    {
        $types = ProjectField::typeNames();

        $html = '<p class="orchestra-muted">' . \esc_html__(
            'Choose in each project which of these questions it asks. Members who accepted answer them while'
                . ' the project is open for participation. Only they, the organizers and the administrators'
                . ' can see the answers.',
            'orchestra-projects'
        ) . '</p>'
            . Form::open(ProjectFieldsController::ACTION, ProjectFieldsController::ACTION, [], [
                'class' => 'orchestra-form',
            ])
            // Enter in a text field saves instead of pressing the first delete button.
            . '<button type="submit" class="orchestra-default-submit" tabindex="-1" aria-hidden="true">'
            . \esc_html__('Save', 'orchestra-projects') . '</button>'
            . '<div class="orchestra-table-wrap"><table class="orchestra-table orchestra-table--stacked">'
            . '<tbody id="' . self::ROWS . '" data-orchestra-sortable>';

        foreach ($this->fields->all() as $field) {
            $name = ProjectFieldsInput::FIELD_FIELDS . '[' . $field->id . ']';
            /* translators: %s: field label */
            $confirm = \__(
                'Delete the field "%s"? The answers of all projects are deleted too.',
                'orchestra-projects'
            );
            $delete = '<button type="submit" class="orchestra-button orchestra-button--small orchestra-button--danger"'
                . ' name="' . ProjectFieldsInput::FIELD_DELETE . '" value="' . \esc_attr($field->id) . '"'
                . ' formnovalidate data-orchestra-confirm="' . \esc_attr(\sprintf($confirm, $field->label)) . '">'
                . \esc_html__('Delete', 'orchestra-projects') . '</button>';
            $cells = [
                ['', self::header(
                    $field->id,
                    $field->label,
                    '<strong>' . \esc_html($types[$field->type] ?? $field->type) . '</strong>',
                    $delete
                )],
                [
                    \__('Label', 'orchestra-projects'),
                    self::textInput($name . '[label]', $field->label, ProjectField::LABEL_MAX_LENGTH, true),
                ],
                [
                    \__('Hint', 'orchestra-projects'),
                    self::textInput($name . '[hint]', $field->hint, ProjectField::HINT_MAX_LENGTH),
                ],
            ];
            if ($field->type === ProjectField::TYPE_SELECT) {
                $cells[] = [\__('Choices', 'orchestra-projects'), self::choicesInput($name, $field->choices)];
                $cells[] = [\__('Other', 'orchestra-projects'), self::otherInput($name, $field->allowsOther)];
            }
            $cells[] = [\__('Required', 'orchestra-projects'), self::requiredInput($name, $field->required)];
            $html .= self::row($cells);
        }

        $html .= self::newRow('0') . '</tbody></table></div>'
            . '<template id="' . self::NEW_ROW_TEMPLATE . '">' . self::newRow('__INDEX__') . '</template>'
            . '<div class="orchestra-actions"><button type="button" class="orchestra-button orchestra-button--small"'
            . ' data-orchestra-add-row="' . self::NEW_ROW_TEMPLATE . '" data-orchestra-target="' . self::ROWS . '">'
            . \esc_html__('+ Add field', 'orchestra-projects') . '</button></div>'
            . '<p class="orchestra-muted">' . \esc_html__(
                'Change the order with the arrow buttons. For checkboxes, the hint is the text next to'
                    . ' the box.',
                'orchestra-projects'
            ) . '</p>'
            . '<div class="orchestra-actions">' . Form::submit(\__('Save', 'orchestra-projects'), 'primary') . '</div>'
            . '</form>';

        return $html;
    }

    private static function newRow(string $key): string
    {
        $name = ProjectFieldsInput::FIELD_NEW_FIELDS . '[' . $key . ']';
        $options = '';
        foreach (ProjectField::typeNames() as $type => $label) {
            $options .= '<option value="' . \esc_attr($type) . '">' . \esc_html($label) . '</option>';
        }

        return self::row([
            ['', self::header(
                ProjectFieldsInput::NEW_KEY_PREFIX . $key,
                \__('New field', 'orchestra-projects'),
                '<select name="' . \esc_attr($name) . '[type]" data-orchestra-switch aria-label="'
                    . \esc_attr__('Type', 'orchestra-projects') . '">' . $options . '</select>',
                '<button type="button" class="orchestra-button orchestra-button--small" data-orchestra-remove-row>'
                    . \esc_html__('Remove', 'orchestra-projects') . '</button>'
            )],
            [
                \__('Label', 'orchestra-projects'),
                '<input type="text" name="' . \esc_attr($name) . '[label]" maxlength="' . ProjectField::LABEL_MAX_LENGTH
                    . '" placeholder="' . \esc_attr__('New field, e.g. Arrival by bus', 'orchestra-projects') . '">',
            ],
            [\__('Hint', 'orchestra-projects'), self::textInput($name . '[hint]', '', ProjectField::HINT_MAX_LENGTH)],
            // Shown for the chosen type only (data-orchestra-switch of orchestra-core 0.14).
            [
                \__('Choices', 'orchestra-projects'),
                self::choicesInput($name, [], \__('One choice per line', 'orchestra-projects')),
                ProjectField::TYPE_SELECT,
            ],
            [\__('Other', 'orchestra-projects'), self::otherInput($name, false), ProjectField::TYPE_SELECT],
            [\__('Required', 'orchestra-projects'), self::requiredInput($name, false)],
        ]);
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $cells Label (plain text, "" for none),
     *     content (HTML) and, for new fields, the type the cell is shown for
     */
    private static function row(array $cells): string
    {
        $html = '<tr>';
        foreach ($cells as $cell) {
            $showFor = isset($cell[2]) ? ' data-orchestra-show-for="' . \esc_attr($cell[2]) . '"' : '';
            $html .= '<td data-label="' . \esc_attr($cell[0]) . '"' . $showFor . '><div class="orchestra-cell">'
                . $cell[1] . '</div></td>';
        }

        return $html . '</tr>';
    }

    /**
     * The top line of a row: the buttons that move the field up or down, with the hidden field that
     * submits its place in the order, the type and the button that deletes or removes the field.
     */
    private static function header(string $key, string $label, string $type, string $action): string
    {
        return '<div class="orchestra-row-header">' . Form::hidden(ProjectFieldsInput::FIELD_ORDER . '[]', $key)
            // The arrow buttons exist since orchestra-core 0.19; without them, the rows keep their order.
            . (\in_array('moveButtons', \get_class_methods(Html::class), true) ? Html::moveButtons($label) : '')
            . $type
            . '<span class="orchestra-row-header__end">' . $action . '</span></div>';
    }

    private static function textInput(string $name, string $value, int $maxLength, bool $required = false): string
    {
        return '<input type="text" name="' . \esc_attr($name) . '" value="' . \esc_attr($value) . '" maxlength="'
            . $maxLength . '"' . ($required ? ' required' : '') . '>';
    }

    private static function requiredInput(string $name, bool $checked): string
    {
        return self::checkbox(
            $name . '[required]',
            $checked,
            \__('Required', 'orchestra-projects'),
            \__('Members who accepted are asked to answer it; a checkbox has to be ticked.', 'orchestra-projects')
        );
    }

    private static function otherInput(string $name, bool $checked): string
    {
        return self::checkbox(
            $name . '[other]',
            $checked,
            \__('Offer "Other" with a free-text answer', 'orchestra-projects'),
            \sprintf(
                /* translators: %d: maximum number of characters */
                \__('Members can write their own answer, at most %d characters.', 'orchestra-projects'),
                ProjectField::OTHER_MAX_LENGTH
            )
        );
    }

    private static function checkbox(string $name, bool $checked, string $label, string $hint): string
    {
        return '<label class="orchestra-checkbox"><input type="checkbox" name="' . \esc_attr($name) . '" value="1"'
            . \checked($checked, true, false) . '> <span>' . \esc_html($label) . '</span></label>'
            . '<span class="orchestra-muted">' . \esc_html($hint) . '</span>';
    }

    /**
     * @param list<string> $choices
     */
    private static function choicesInput(string $name, array $choices, string $placeholder = ''): string
    {
        return '<textarea name="' . \esc_attr($name) . '[choices]" rows="3" aria-label="'
            . \esc_attr__('Choices, one per line', 'orchestra-projects') . '"'
            . ($placeholder === '' ? '' : ' placeholder="' . \esc_attr($placeholder) . '"') . '>'
            . \esc_textarea(\implode("\n", $choices)) . '</textarea>';
    }
}
