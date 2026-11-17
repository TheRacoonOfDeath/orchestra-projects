<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Notices;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Field\AnswerRepository;
use Orchestra\Projects\Field\ProjectFields;
use Orchestra\Projects\Field\ProjectFieldsInput;

/**
 * Saves the project fields (admin-post.php?action=orchestra_projects_save_fields).
 */
final class ProjectFieldsController
{
    public const ACTION = 'orchestra_projects_save_fields';

    public function __construct(
        private readonly ProjectFields $fields,
        private readonly AnswerRepository $answers,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * @internal Hooked to `admin_post_orchestra_projects_save_fields`.
     */
    public function handle(): never
    {
        \check_admin_referer(self::ACTION);
        if (!\current_user_can(Capabilities::FIELDS_MANAGE)) {
            \wp_die(\esc_html__('You are not allowed to change the project fields.', 'orchestra-projects'), 403);
        }

        $input = ProjectFieldsInput::fromForm(\wp_unslash($_POST), $this->fields->all());
        if ($input->errors !== []) {
            Notices::errors($input->errors);
            Redirect::back();
        }

        $this->fields->save($input->fields);
        foreach ($input->removed as $field) {
            $this->answers->deleteForField($field->id);
            $this->fields->forget($field->id);
        }

        Notices::success(\__('The project fields have been saved.', 'orchestra-projects'));
        Redirect::back();
    }
}
