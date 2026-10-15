<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Projects\Admin\ProjectActions;

/**
 * Forms for the operations of {@see ProjectActions}.
 */
final class ProjectForms
{
    /**
     * @param array<string, string|int> $fields
     * @param array<string, string> $attributes
     */
    public static function open(int $projectId, string $operation, array $fields = [], array $attributes = []): string
    {
        return Form::open(
            ProjectActions::ACTION,
            ProjectActions::nonceAction($projectId),
            ['project' => $projectId, 'op' => $operation] + $fields,
            $attributes
        );
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
        string $style = 'small',
    ): string {
        return Form::button(
            ProjectActions::ACTION,
            ProjectActions::nonceAction($projectId),
            $label,
            ['project' => $projectId, 'op' => $operation] + $fields,
            $confirm,
            $style
        );
    }
}
