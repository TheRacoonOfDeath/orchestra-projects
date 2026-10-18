<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Projects\Admin\EmailLinks;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Email\ProjectEmailGroups;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\Project\StaffRepository;

/**
 * A project with its tabs. Members see the overview; conductors and organizers also the program,
 * participation and assignments.
 */
final class ProjectView
{
    public const OVERVIEW = 'overview';
    public const PROGRAM = 'program';
    public const PARTICIPATION = 'participation';
    public const ASSIGNMENTS = 'assignments';

    public function __construct(
        private readonly StaffRepository $staff,
        private readonly Names $names,
        private readonly MemberProjectView $memberView,
        private readonly ProgramView $programView,
        private readonly ParticipationView $participationView,
        private readonly AssignmentsView $assignmentsView,
        private readonly EmailLinks $emailLinks,
    ) {
    }

    /**
     * Whether the current user may see the project at all.
     */
    public static function canSee(Project $project): bool
    {
        return \current_user_can(Capabilities::PROGRAM_MANAGE)
            || ($project->status->visibleToMembers() && \current_user_can(Capabilities::PROJECT_READ));
    }

    /**
     * @return array<string, string> the tabs the current user may open
     */
    public static function tabs(): array
    {
        $tabs = [self::OVERVIEW => \__('Overview', 'orchestra-projects')];
        if (\current_user_can(Capabilities::PROGRAM_MANAGE)) {
            $tabs[self::PROGRAM] = \__('Program', 'orchestra-projects');
        }
        if (\current_user_can(Capabilities::PARTICIPATION_READ_OTHERS)) {
            $tabs[self::PARTICIPATION] = \__('Participation', 'orchestra-projects');
        }
        if (\current_user_can(Capabilities::PROGRAM_MANAGE) && \current_user_can(Capabilities::ASSIGNMENT_READ)) {
            $tabs[self::ASSIGNMENTS] = \__('Assignments', 'orchestra-projects');
        }

        return $tabs;
    }

    public function render(Project $project, string $tab, ProjectUrls $urls, bool $withHeading = true): string
    {
        $tabs = self::tabs();
        $tab = isset($tabs[$tab]) ? $tab : self::OVERVIEW;

        $completed = $project->status === ProjectStatus::Completed;
        $html = '<p class="orchestra-back"><a href="' . \esc_url(ProjectListView::url($urls, $completed)) . '">&larr; '
            . ($completed
                ? \esc_html__('Completed projects', 'orchestra-projects')
                : \esc_html__('All projects', 'orchestra-projects'))
            . '</a></p>';
        if ($withHeading) {
            $edit = \current_user_can('edit_post', $project->id)
                ? Form::link($urls->edit($project->id), \__('Edit', 'orchestra-projects'), 'small')
                : '';
            $html .= '<div class="orchestra-toolbar"><h2>' . \esc_html($project->title) . ' '
                . Badges::project($project->status)
                . '</h2>' . $edit . '</div>';
        }
        if (\count($tabs) > 1) {
            $html .= Html::tabs($tabs, $tab, static fn (string $key): string => $urls->project($project->id, $key));
        }

        return $html . match ($tab) {
            self::PROGRAM => $this->programView->manage($project),
            self::PARTICIPATION => $this->participationView->render($project),
            self::ASSIGNMENTS => $this->assignmentsView->render($project),
            default => $this->overview($project, !$withHeading),
        };
    }

    /**
     * @param bool $showStatus Whether to list the status, which is otherwise shown next to the title
     */
    private function overview(Project $project, bool $showStatus): string
    {
        $facts = $showStatus ? [[\__('Status', 'orchestra-projects'), Badges::project($project->status)]] : [];
        $facts = [
            ...$facts,
            [\__('Dates', 'orchestra-projects'), \esc_html(Dates::range($project))],
            [Dates::concertsLabel($project), \esc_html(Dates::concerts($project) ?: '—')],
            [
                \__('Conductors', 'orchestra-projects'),
                \esc_html($this->names->list($this->staff->userIds($project->id, StaffRepository::CONDUCTOR))),
            ],
            [
                \__('Organizers', 'orchestra-projects'),
                \esc_html($this->names->list($this->staff->userIds($project->id, StaffRepository::ORGANIZER))),
            ],
        ];
        if (\current_user_can(Capabilities::PARTICIPATION_READ_OTHERS)) {
            $summary = $this->participationView->summary($project);
            $facts[] = [\__('Participation', 'orchestra-projects'), \esc_html($summary)];
        }

        $html = '<div class="orchestra-card">' . Html::details($facts);
        if (\trim($project->description) !== '') {
            $html .= '<div class="orchestra-description">' . \wp_kses_post(\wpautop($project->description)) . '</div>';
        }
        $html .= '</div>';

        $own = $this->memberView->participation($project) . $this->memberView->parts($project);
        if ($own !== '') {
            $html .= '<div class="orchestra-card orchestra-stack">' . $own . '</div>';
        }

        $html .= '<section class="orchestra-stack"><h3>' . \esc_html__('Program', 'orchestra-projects') . '</h3>'
            . $this->programView->list($project) . '</section>';

        $email = $this->emailLinks->buttons($project, [
            ProjectEmailGroups::participantsGroupId($project->id, ParticipationStatus::Accepted)
                => \__('Email accepted participants', 'orchestra-projects'),
            ProjectEmailGroups::participantsGroupId($project->id, ParticipationStatus::Open)
                => \__('Email pending participants', 'orchestra-projects'),
            ProjectEmailGroups::participantsGroupId($project->id) => \__('Email everyone asked', 'orchestra-projects'),
        ]);
        if ($email !== '') {
            $html .= '<section class="orchestra-stack"><h3>' . \esc_html__('Email', 'orchestra-projects') . '</h3>'
                . '<div class="orchestra-actions">' . $email . '</div></section>';
        }
        if (\current_user_can(Capabilities::PROJECT_MANAGE)) {
            $html .= $this->statusButtons($project);
        }

        return $html;
    }

    private function statusButtons(Project $project): string
    {
        $buttons = '';
        foreach ([$project->status->next(), $project->status->previous()] as $index => $target) {
            if ($target === null) {
                continue;
            }
            $confirm = \sprintf(
                /* translators: %s: project status */
                \__('Change the status to "%s"?', 'orchestra-projects'),
                $target->label()
            );
            if ($target === ProjectStatus::Open) {
                $confirm .= ' ' . \__(
                    'All active members and organizers who have not been asked yet will be asked to respond.',
                    'orchestra-projects'
                );
            }
            $label = $index === 0
                /* translators: %s: project status */
                ? \sprintf(\__('Move to "%s"', 'orchestra-projects'), $target->label())
                /* translators: %s: project status */
                : \sprintf(\__('Back to "%s"', 'orchestra-projects'), $target->label());
            $buttons .= ProjectForms::button(
                $project->id,
                'status',
                $label,
                ['target' => $target->value],
                $confirm,
                $index === 0 ? 'primary' : ''
            );
        }

        return $buttons === ''
            ? ''
            : '<section class="orchestra-stack"><h3>' . \esc_html__('Status', 'orchestra-projects') . '</h3>'
                . '<div class="orchestra-actions">' . $buttons . '</div></section>';
    }
}
