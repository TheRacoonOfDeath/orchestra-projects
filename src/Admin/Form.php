<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

/**
 * Small forms posting to {@see ProjectActions}.
 */
final class Form
{
    /**
     * Opens a form for an operation on a project. Close it with `</form>`.
     *
     * @param array<string, string|int> $fields Hidden fields
     * @param string|null $confirm Message the user must confirm before the form is sent
     * @param string|null $onSubmit JavaScript run on submit instead; returning false cancels it
     */
    public static function open(
        int $projectId,
        string $operation,
        array $fields = [],
        ?string $confirm = null,
        ?string $onSubmit = null,
    ): string {
        if ($onSubmit === null && $confirm !== null) {
            $onSubmit = 'return confirm(' . self::jsString($confirm) . ');';
        }
        $html = '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '" style="display:inline"'
            . ($onSubmit === null ? '' : ' onsubmit="' . \esc_attr($onSubmit) . '"')
            . '>';
        $fields = ['action' => ProjectActions::ACTION, 'project' => $projectId, 'op' => $operation] + $fields;
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . \esc_attr($name) . '" value="' . \esc_attr((string) $value) . '">';
        }

        return $html . \wp_nonce_field(ProjectActions::nonceAction($projectId), '_wpnonce', true, false);
    }

    /**
     * A string literal for JavaScript.
     */
    public static function jsString(string $text): string
    {
        return (string) \wp_json_encode($text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * @param array<string, string|int> $fields
     */
    public static function button(
        int $projectId,
        string $operation,
        string $label,
        array $fields = [],
        ?string $confirm = null,
        string $class = 'button',
    ): string {
        return self::open($projectId, $operation, $fields, $confirm)
            . '<button type="submit" class="' . \esc_attr($class) . '">' . \esc_html($label) . '</button></form>';
    }
}
