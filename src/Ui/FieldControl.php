<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Projects\Field\ProjectField;

/**
 * The form control and the displayed value of a {@see ProjectField}, as the profile fields of
 * orchestra-members show them.
 */
final class FieldControl
{
    /**
     * The input element; checkboxes come with their text (the field's hint). Required fields are
     * `required`. Escaped.
     *
     * @param string $class CSS class of text inputs, e.g. "regular-text" in wp-admin
     */
    public static function control(
        ProjectField $field,
        string $value,
        string $id,
        bool $disabled = false,
        string $class = ''
    ): string {
        $attributes = ' id="' . \esc_attr($id) . '" name="' . \esc_attr($field->inputName()) . '"'
            . ($disabled ? ' disabled' : '') . ($field->required ? ' required' : '');
        $classAttribute = $class === '' ? '' : ' class="' . \esc_attr($class) . '"';

        switch ($field->type) {
            case ProjectField::TYPE_CHECKBOX:
                return '<label class="orchestra-checkbox"><input type="checkbox" value="1"' . $attributes
                    . \checked($value, '1', false) . '> <span>'
                    . \esc_html($field->hint !== '' ? $field->hint : $field->label) . '</span></label>';
            case ProjectField::TYPE_SELECT:
                $other = $field->otherText($value);
                // A value whose choice was removed stays selectable until it is changed.
                $choices = $value !== '' && $other === '' && !\in_array($value, $field->choices, true)
                    ? [...$field->choices, $value]
                    : $field->choices;
                $options = '<option value="">—</option>';
                foreach ($choices as $choice) {
                    $options .= '<option value="' . \esc_attr($choice) . '"' . \selected($value, $choice, false) . '>'
                        . \esc_html($choice) . '</option>';
                }
                if (!$field->allowsOther) {
                    return '<select' . $attributes . '>' . $options . '</select>';
                }

                $options .= '<option value="' . ProjectField::OTHER . '"' . \selected($other !== '', true, false) . '>'
                    . \esc_html__('Other', 'orchestra-projects') . '</option>';
                /* translators: %s: field label */
                $otherLabel = \sprintf(\__('%s: other answer', 'orchestra-projects'), $field->label);

                return '<div class="orchestra-stack"><select' . $attributes . '>' . $options . '</select>'
                    . ' <input type="text" name="' . \esc_attr(ProjectField::OTHER_FIELD . '[' . $field->id . ']') . '"'
                    . $classAttribute . ' maxlength="' . ProjectField::OTHER_MAX_LENGTH . '" value="'
                    . \esc_attr($other) . '" placeholder="' . \esc_attr__('Other: please specify', 'orchestra-projects')
                    . '" aria-label="' . \esc_attr($otherLabel) . '"' . ($disabled ? ' disabled' : '') . '></div>';
            case ProjectField::TYPE_TEXTAREA:
                return '<textarea' . $attributes . $classAttribute . ' rows="3" maxlength="' . $field->maxLength()
                    . '">' . \esc_textarea($value) . '</textarea>';
            default:
                return '<input type="text"' . $attributes . $classAttribute . ' maxlength="' . $field->maxLength()
                    . '" value="' . \esc_attr($value) . '">';
        }
    }

    /**
     * The hint shown below the control. Checkboxes show it next to the box instead.
     */
    public static function hint(ProjectField $field): string
    {
        return $field->type === ProjectField::TYPE_CHECKBOX ? '' : $field->hint;
    }

    /**
     * The value for display, escaped. "—" if it is empty.
     */
    public static function value(ProjectField $field, string $value): string
    {
        if ($field->type !== ProjectField::TYPE_CHECKBOX && $value === '') {
            return '—';
        }

        return \nl2br(\esc_html($field->display($value)));
    }
}
