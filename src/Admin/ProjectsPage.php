<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\Project\StaffRepository;

/**
 * The "Projects" screen for organizers and conductors: the project list and, per project, the
 * tabs Overview, Program, Participation and Assignments.
 */
final class ProjectsPage
{
    public const SLUG = 'orchestra-projects';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly StaffRepository $staff,
        private readonly ParticipationRepository $participations,
        private readonly Names $names,
        private readonly ProgramTab $programTab,
        private readonly ParticipationTab $participationTab,
        private readonly AssignmentsTab $assignmentsTab,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'addMenu']);
    }

    public static function url(?int $projectId = null, string $tab = 'overview'): string
    {
        $args = ['page' => self::SLUG];
        if ($projectId !== null) {
            $args['project'] = $projectId;
            $args['tab'] = $tab;
        }

        return \add_query_arg($args, \admin_url('admin.php'));
    }

    /**
     * @internal Hooked to `admin_menu`.
     */
    public function addMenu(): void
    {
        $hookSuffix = \add_menu_page(
            \__('Projects', 'orchestra-projects'),
            \__('Projects', 'orchestra-projects'),
            Capabilities::PROGRAM_MANAGE,
            self::SLUG,
            [$this, 'render'],
            'dashicons-calendar-alt',
            72
        );
        \add_action('load-' . $hookSuffix, [$this, 'authorize']);

        // The first submenu entry replaces the top-level one, so it is added explicitly. "Add Project"
        // also gives the project editor its place in the menu, without which WordPress denies it.
        \add_submenu_page(
            self::SLUG,
            \__('Projects', 'orchestra-projects'),
            \__('All Projects', 'orchestra-projects'),
            Capabilities::PROGRAM_MANAGE,
            self::SLUG
        );
        \add_submenu_page(
            self::SLUG,
            \__('Add Project', 'orchestra-projects'),
            \__('Add Project', 'orchestra-projects'),
            Capabilities::PROJECT_MANAGE,
            'post-new.php?post_type=' . ProjectPostType::NAME
        );
    }

    /**
     * Rejects unknown projects and tabs before any output is sent.
     *
     * @internal Hooked to `load-{page}`.
     */
    public function authorize(): void
    {
        $projectId = self::requestedProjectId();
        if ($projectId === 0) {
            return;
        }
        if ($this->projects->find($projectId) === null) {
            \wp_die(\esc_html__('The project does not exist.', 'orchestra-projects'), 404);
        }
        if (!\array_key_exists(self::requestedTab(), $this->tabs())) {
            \wp_die(\esc_html__('You are not allowed to view this page.', 'orchestra-projects'), 403);
        }
    }

    /**
     * @internal Menu page callback.
     */
    public function render(): void
    {
        $project = $this->projects->find(self::requestedProjectId());
        if ($project === null) {
            $this->renderList();
            return;
        }

        $tab = self::requestedTab();
        echo '<div class="wrap"><h1>' . \esc_html($project->title) . '</h1>';
        echo '<p><a href="' . \esc_url(self::url()) . '">&larr; ' . \esc_html__('All projects', 'orchestra-projects')
            . '</a></p>';
        echo '<nav class="nav-tab-wrapper">';
        foreach ($this->tabs() as $key => $label) {
            \printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                \esc_url(self::url($project->id, $key)),
                $key === $tab ? ' nav-tab-active' : '',
                \esc_html($label)
            );
        }
        echo '</nav>';

        match ($tab) {
            'program' => $this->programTab->render($project),
            'participation' => $this->participationTab->render($project),
            'assignments' => $this->assignmentsTab->render($project),
            default => $this->renderOverview($project),
        };
        echo '</div>';
    }

    /**
     * @return array<string, string> the tabs the current user may open
     */
    private function tabs(): array
    {
        $tabs = [
            'overview' => \__('Overview', 'orchestra-projects'),
            'program' => \__('Program', 'orchestra-projects'),
        ];
        if (\current_user_can(Capabilities::PARTICIPATION_READ_OTHERS)) {
            $tabs['participation'] = \__('Participation', 'orchestra-projects');
        }
        if (\current_user_can(Capabilities::ASSIGNMENT_READ)) {
            $tabs['assignments'] = \__('Assignments', 'orchestra-projects');
        }

        return $tabs;
    }

    private function renderList(): void
    {
        echo '<div class="wrap"><h1 class="wp-heading-inline">' . \esc_html__('Projects', 'orchestra-projects')
            . '</h1>';
        if (\current_user_can(Capabilities::PROJECT_MANAGE)) {
            echo ' <a class="page-title-action" href="'
                . \esc_url(\admin_url('post-new.php?post_type=' . ProjectPostType::NAME)) . '">'
                . \esc_html__('Add Project', 'orchestra-projects') . '</a>';
        }
        echo '<hr class="wp-header-end">';

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (
            [
            \__('Project', 'orchestra-projects'),
            \__('Status', 'orchestra-projects'),
            \__('Dates', 'orchestra-projects'),
            \__('Concert', 'orchestra-projects'),
            \__('Participation', 'orchestra-projects'),
            ] as $heading
        ) {
            echo '<th scope="col">' . \esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $projects = $this->projects->all();
        if ($projects === []) {
            echo '<tr><td colspan="5">' . \esc_html__('No projects yet.', 'orchestra-projects') . '</td></tr>';
        }
        foreach ($projects as $project) {
            $actions = '<a href="' . \esc_url(self::url($project->id)) . '">'
                . \esc_html__('Manage', 'orchestra-projects') . '</a>';
            if (\current_user_can('edit_post', $project->id)) {
                $actions .= ' | <a href="' . \esc_url((string) \get_edit_post_link($project->id)) . '">'
                    . \esc_html__('Edit', 'orchestra-projects') . '</a>';
            }
            \printf(
                '<tr><td><strong><a href="%s">%s</a></strong><div class="row-actions visible">%s</div></td>'
                . '<td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                \esc_url(self::url($project->id)),
                \esc_html($project->title),
                $actions, // escaped above
                \esc_html($project->status->label()),
                \esc_html(self::dateRange($project)),
                \esc_html(self::date($project->concertDate)),
                \esc_html($this->participationSummary($project))
            );
        }
        echo '</tbody></table></div>';
    }

    private function renderOverview(Project $project): void
    {
        $rows = [
            [\__('Status', 'orchestra-projects'), \esc_html($project->status->label())],
            [\__('Dates', 'orchestra-projects'), \esc_html(self::dateRange($project))],
            [\__('Concert', 'orchestra-projects'), \esc_html(self::date($project->concertDate))],
            [
                \__('Conductors', 'orchestra-projects'),
                \esc_html($this->names->list($this->staff->userIds($project->id, StaffRepository::CONDUCTOR))),
            ],
            [
                \__('Organizers', 'orchestra-projects'),
                \esc_html($this->names->list($this->staff->userIds($project->id, StaffRepository::ORGANIZER))),
            ],
            [\__('Participation', 'orchestra-projects'), \esc_html($this->participationSummary($project))],
            [\__('Description', 'orchestra-projects'), \wp_kses_post(\wpautop($project->description))],
        ];
        echo '<table class="form-table" role="presentation">';
        foreach ($rows as [$label, $value]) {
            echo '<tr><th scope="row">' . \esc_html($label) . '</th><td>' . $value . '</td></tr>'; // escaped above
        }
        echo '</table>';

        if (\current_user_can('edit_post', $project->id)) {
            echo '<p><a class="button" href="' . \esc_url((string) \get_edit_post_link($project->id)) . '">'
                . \esc_html__('Edit name, dates and staff', 'orchestra-projects') . '</a></p>';
        }
        if (\current_user_can(Capabilities::PROJECT_MANAGE)) {
            $this->renderStatusButtons($project);
        }
    }

    private function renderStatusButtons(Project $project): void
    {
        echo '<h2>' . \esc_html__('Change status', 'orchestra-projects') . '</h2><p>';
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
            echo Form::button(
                $project->id,
                'status',
                $label,
                ['target' => $target->value],
                $confirm,
                $index === 0 ? 'button button-primary' : 'button'
            ) . ' ';
        }
        echo '</p>';
    }

    private function participationSummary(Project $project): string
    {
        $counts = \array_fill_keys(\array_map(static fn ($s) => $s->value, ParticipationStatus::cases()), 0);
        foreach ($this->participations->forProject($project->id) as $participation) {
            $counts[$participation->status->value]++;
        }

        return \sprintf(
            /* translators: 1: number accepted, 2: number declined, 3: number pending */
            \__('%1$d accepted, %2$d declined, %3$d pending', 'orchestra-projects'),
            $counts[ParticipationStatus::Accepted->value],
            $counts[ParticipationStatus::Declined->value],
            $counts[ParticipationStatus::Open->value]
        );
    }

    public static function dateRange(Project $project): string
    {
        if ($project->startDate === null && $project->endDate === null) {
            return '—';
        }

        return self::date($project->startDate) . ' – ' . self::date($project->endDate);
    }

    public static function date(?string $date): string
    {
        if ($date === null) {
            return '—';
        }
        $timestamp = \strtotime($date . ' 12:00:00');

        return $timestamp === false ? $date : \date_i18n(self::dateFormat(), $timestamp);
    }

    public static function dateFormat(): string
    {
        $format = \get_option('date_format');

        return \is_string($format) && $format !== '' ? $format : 'Y-m-d';
    }

    private static function requestedProjectId(): int
    {
        return \absint(\is_string($_GET['project'] ?? null) ? $_GET['project'] : 0);
    }

    private static function requestedTab(): string
    {
        return \is_string($_GET['tab'] ?? null) ? \sanitize_key($_GET['tab']) : 'overview';
    }
}
