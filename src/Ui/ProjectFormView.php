<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Members\MembersService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Field\ProjectFields;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectDeletion;
use Orchestra\Projects\Project\ProjectFormHandler;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\StaffRepository;

/**
 * Creating and editing a project: name, description, dates and staff. The date and staff fields
 * are also shown in the meta boxes of the project editor in wp-admin.
 */
final class ProjectFormView
{
    public const FIELD_TITLE = 'orchestra_projects_title';
    public const FIELD_DESCRIPTION = 'orchestra_projects_description';

    private const CONCERTS = 'orchestra-projects-concerts';
    private const NEW_CONCERT_TEMPLATE = 'orchestra-projects-new-concert';

    public function __construct(
        private readonly StaffRepository $staff,
        private readonly MembersService $members,
        private readonly ProjectFields $fields,
        private readonly ProjectDeletion $deletion,
        private readonly bool $reminders = false,
    ) {
    }

    public function render(?Project $project, ProjectUrls $urls): string
    {
        $projectId = $project->id ?? 0;
        $back = $project === null ? $urls->list() : $urls->project($project->id);
        $heading = $project === null
            ? \__('New project', 'orchestra-projects')
            : \__('Edit project', 'orchestra-projects');
        // Saving an existing project returns to it; a new one is opened by the handler.
        $fields = $project === null ? [] : ['redirect_to' => $back];

        return '<p class="orchestra-back"><a href="' . \esc_url($back) . '">&larr; '
            . \esc_html($project === null ? \__('All projects', 'orchestra-projects') : $project->title) . '</a></p>'
            . '<h2>' . \esc_html($heading)
            . '</h2>'
            . ProjectForms::open($projectId, 'save', $fields, ['class' => 'orchestra-form'])
            . '<div class="orchestra-card orchestra-stack">'
            . Html::field(
                'orchestra-projects-title',
                \__('Name', 'orchestra-projects'),
                '<input type="text" id="orchestra-projects-title" name="' . self::FIELD_TITLE . '" required value="'
                    . \esc_attr($project->title ?? '') . '" placeholder="'
                    . \esc_attr__('e.g. Spring Concert 2027', 'orchestra-projects')
                    . '">'
            )
            . Html::field(
                'orchestra-projects-description',
                \__('Description', 'orchestra-projects'),
                '<textarea id="orchestra-projects-description" name="' . self::FIELD_DESCRIPTION . '" rows="5">'
                    . \esc_textarea($project->description ?? '') . '</textarea>',
                \__('Shown to all members, e.g. rehearsal times and places.', 'orchestra-projects')
            )
            . $this->dateFields($project)
            . '</div>'
            . '<div class="orchestra-card orchestra-stack">' . $this->staffFields($projectId) . '</div>'
            . '<div class="orchestra-card orchestra-stack">' . $this->fieldChoice($projectId, $urls) . '</div>'
            . '<div class="orchestra-actions">' . Form::submit(\__('Save', 'orchestra-projects'), 'primary')
            . Form::link($back, \__('Cancel', 'orchestra-projects')) . '</div></form>'
            . ($project === null ? '' : $this->deleteForm($project, $urls));
    }

    /**
     * "Delete project" below the form, for those who may delete this project. Its own form, since
     * forms cannot be nested; it returns to the project list.
     */
    private function deleteForm(Project $project, ProjectUrls $urls): string
    {
        if (!$this->deletion->canDelete($project)) {
            return '';
        }

        return '<div class="orchestra-actions">' . ProjectForms::button(
            $project->id,
            'delete',
            \__('Delete project', 'orchestra-projects'),
            ['redirect_to' => $urls->list()],
            \sprintf(
                /* translators: %s: name of the project */
                \__(
                    'Delete the project %s permanently, with its program, participation and assignments?'
                        . ' This cannot be undone.',
                    'orchestra-projects'
                ),
                $project->title
            ),
            'danger'
        ) . '</div>';
    }


    public function dateFields(?Project $project): string
    {
        // The end date follows the start date while it is empty or earlier, so its picker opens in the
        // right month (assets/orchestra-projects.js).
        Assets::enqueueScript();
        $fields = [
            [ProjectPostType::META_START_DATE, \__('Start date', 'orchestra-projects'), $project?->startDate, 'start'],
            [ProjectPostType::META_END_DATE, \__('End date', 'orchestra-projects'), $project?->endDate, 'end'],
        ];
        $html = '<div class="orchestra-row">';
        foreach ($fields as [$name, $label, $value, $role]) {
            $html .= Html::field(
                $name,
                $label,
                '<input type="date" id="' . \esc_attr($name) . '" name="' . \esc_attr($name) . '" value="'
                    . \esc_attr($value ?? '') . '" data-orchestra-projects-date="' . $role . '"'
                    . ($role === 'end' && $project?->startDate !== null
                        ? ' min="' . \esc_attr($project->startDate) . '"'
                        : '')
                    . '>'
            );
        }

        return $html . '</div>' . Html::field(
            ProjectPostType::META_DEADLINE,
            \__('Registration deadline', 'orchestra-projects'),
            '<input type="date" id="' . ProjectPostType::META_DEADLINE . '" name="' . ProjectPostType::META_DEADLINE
                . '" value="' . \esc_attr($project->registrationDeadline ?? '') . '" style="width:auto">',
            \__(
                'Until when members should respond. Shown while the project is open for participation.',
                'orchestra-projects'
            )
                . ($this->reminders ? ' ' . \__(
                    'Members who have not responded are reminded by email seven days before.',
                    'orchestra-projects'
                ) : '')
        ) . self::concertFields($project->concertDates ?? []);
    }

    /**
     * One date field per concert. New projects have none; "Add concert" adds a field, and
     * without JavaScript there is always one empty field.
     *
     * @param list<string> $dates
     */
    private static function concertFields(array $dates): string
    {
        return '<fieldset class="orchestra-fieldset orchestra-stack"><legend>'
            . \esc_html__('Concerts', 'orchestra-projects') . '</legend>'
            . '<div class="orchestra-stack" id="' . self::CONCERTS . '">'
            . \implode('', \array_map(static fn (string $date): string => self::concertRow($date, true), $dates))
            . '</div>'
            . '<noscript>' . self::concertRow('', false) . '</noscript>'
            . '<template id="' . self::NEW_CONCERT_TEMPLATE . '">' . self::concertRow('', true) . '</template>'
            . '<div class="orchestra-actions"><button type="button" class="orchestra-button orchestra-button--small"'
            . ' data-orchestra-add-row="' . self::NEW_CONCERT_TEMPLATE . '" data-orchestra-target="' . self::CONCERTS
            . '">' . \esc_html__('+ Add concert', 'orchestra-projects') . '</button></div>'
            . '<p class="orchestra-muted">'
            . \esc_html__('A project can have any number of concerts, or none.', 'orchestra-projects') . '</p>'
            . '</fieldset>';
    }

    private static function concertRow(string $date, bool $removable): string
    {
        $remove = $removable
            ? '<button type="button" class="orchestra-button orchestra-button--small" data-orchestra-remove-row>'
                . \esc_html__('Remove', 'orchestra-projects') . '</button>'
            : '';

        return '<div class="orchestra-actions" data-orchestra-row><input type="date" name="'
            . ProjectPostType::META_CONCERT_DATE . '[]" value="' . \esc_attr($date) . '" aria-label="'
            . \esc_attr__('Concert date', 'orchestra-projects') . '" style="width:auto">' . $remove . '</div>';
    }

    /**
     * Which of the project fields the project asks its participants.
     *
     * @param ?ProjectUrls $urls For the link to the project fields; none in the meta box of wp-admin,
     *     which has a menu entry for them
     */
    public function fieldChoice(int $projectId, ?ProjectUrls $urls = null): string
    {
        $html = '<fieldset class="orchestra-fieldset"><legend>' . \esc_html__('Questions', 'orchestra-projects')
            . '</legend>'
            // Tells the handler that the choice was in the form, also when nothing is ticked.
            . Form::hidden(ProjectFormHandler::FIELD_FIELDS_SHOWN, '1');
        $fields = $this->fields->all();
        if ($fields === []) {
            $html .= '<p class="orchestra-muted">'
                . \esc_html__('There are no project fields yet.', 'orchestra-projects') . '</p>';
        }
        $selected = $projectId > 0 ? $this->fields->selectedIds($projectId) : [];
        $html .= '<div class="orchestra-checklist">';
        foreach ($fields as $field) {
            $html .= '<label class="orchestra-checkbox"><input type="checkbox" name="'
                . ProjectFormHandler::FIELD_FIELDS . '[]" value="' . \esc_attr($field->id) . '"'
                . \checked(\in_array($field->id, $selected, true), true, false) . '> <span>'
                . \esc_html($field->label . ($field->required ? ' *' : '')) . '</span></label>';
        }
        $html .= '</div></fieldset><p class="orchestra-muted">' . \esc_html__(
            'Members who accepted answer the chosen questions while the project is open for participation;'
                . ' * marks required ones. Unticking a question hides its answers in this project.',
            'orchestra-projects'
        ) . '</p>';
        if ($urls !== null && \current_user_can(Capabilities::FIELDS_MANAGE)) {
            $html .= '<p><a href="' . \esc_url($urls->fields()) . '">'
                . \esc_html__('Manage the project fields', 'orchestra-projects') . '</a></p>';
        }

        return $html;
    }

    public function staffFields(int $projectId): string
    {
        $labels = [
            StaffRepository::CONDUCTOR => \__('Conductors', 'orchestra-projects'),
            StaffRepository::ORGANIZER => \__('Organizers', 'orchestra-projects'),
        ];

        $html = '';
        foreach (ProjectFormHandler::staffRoles() as $staffRole => $role) {
            $selected = $projectId > 0 ? $this->staff->userIds($projectId, $staffRole) : [];
            $html .= '<fieldset class="orchestra-fieldset"><legend>' . \esc_html($labels[$staffRole]) . '</legend>';
            $candidates = $this->members->members([$role]);
            if ($candidates === []) {
                $html .= '<p class="orchestra-muted">'
                    . \esc_html__('Nobody has this role yet.', 'orchestra-projects') . '</p>';
            }
            $html .= '<div class="orchestra-checklist">';
            foreach ($candidates as $member) {
                $html .= '<label class="orchestra-checkbox"><input type="checkbox" name="'
                    . ProjectFormHandler::FIELD_STAFF . '[' . $staffRole . '][]" value="' . $member->id . '"'
                    . \checked(\in_array($member->id, $selected, true), true, false) . '> <span>'
                    . \esc_html($member->displayName) . '</span></label>';
            }
            $html .= '</div></fieldset>';
        }

        return $html . '<p class="orchestra-muted">' . \esc_html__(
            'Shown on the project page. Every conductor and organizer has their rights in all projects.',
            'orchestra-projects'
        ) . '</p>';
    }
}
