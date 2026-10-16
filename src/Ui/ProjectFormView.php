<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Members\MembersService;
use Orchestra\Projects\Project\Project;
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

    public function __construct(
        private readonly StaffRepository $staff,
        private readonly MembersService $members,
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
            . '<div class="orchestra-actions">' . Form::submit(\__('Save', 'orchestra-projects'), 'primary')
            . Form::link($back, \__('Cancel', 'orchestra-projects')) . '</div></form>';
    }

    public function dateFields(?Project $project): string
    {
        $fields = [
            [ProjectPostType::META_START_DATE, \__('Start date', 'orchestra-projects'), $project?->startDate],
            [ProjectPostType::META_END_DATE, \__('End date', 'orchestra-projects'), $project?->endDate],
            [ProjectPostType::META_CONCERT_DATE, \__('Concert date', 'orchestra-projects'), $project?->concertDate],
        ];
        $html = '<div class="orchestra-row">';
        foreach ($fields as [$name, $label, $value]) {
            $html .= Html::field(
                $name,
                $label,
                '<input type="date" id="' . \esc_attr($name) . '" name="' . \esc_attr($name) . '" value="'
                    . \esc_attr($value ?? '') . '">'
            );
        }

        return $html . '</div>';
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
