<?php

declare(strict_types=1);

namespace Orchestra\Projects\Frontend;

use Orchestra\Library\LibraryService;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Admin\Notices;
use Orchestra\Projects\Admin\ProjectsPage;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationRules;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\StaffRepository;

/**
 * `[orchestra_projects]`: the member dashboard with the current projects, the member's response,
 * assignments and sheet music, and the page of a single project (`?orchestra_project=ID`).
 */
final class ProjectsShortcode
{
    public const TAG = 'orchestra_projects';
    public const QUERY_PARAMETER = 'orchestra_project';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly StaffRepository $staff,
        private readonly ProgramRepository $program,
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
        private readonly LibraryService $library,
        private readonly Names $names,
        private readonly Notices $notices,
    ) {
    }

    public function register(): void
    {
        \add_shortcode(self::TAG, [$this, 'render']);
    }

    /**
     * @internal Shortcode callback.
     */
    public function render(): string
    {
        if (!\is_user_logged_in()) {
            return \sprintf(
                '<p>%s <a href="%s">%s</a></p>',
                \esc_html__('Please log in to see the projects.', 'orchestra-projects'),
                \esc_url(\wp_login_url((string) \get_permalink())),
                \esc_html__('Log in', 'orchestra-projects')
            );
        }
        if (!\current_user_can(Capabilities::PROJECT_READ)) {
            return '<p>' . \esc_html__('You are not allowed to see the projects.', 'orchestra-projects') . '</p>';
        }

        $html = '<div class="orchestra-projects">' . $this->renderNotices();
        $projectId = \absint(\is_string($_GET[self::QUERY_PARAMETER] ?? null) ? $_GET[self::QUERY_PARAMETER] : 0);
        if ($projectId > 0) {
            $project = $this->projects->find($projectId);
            $html .= $project !== null && $this->canSee($project)
                ? $this->renderProject($project)
                : '<p>' . \esc_html__('The project does not exist.', 'orchestra-projects') . '</p>';
        } else {
            $html .= $this->renderDashboard();
        }

        return $html . '</div>';
    }

    private function canSee(Project $project): bool
    {
        return $project->status->visibleToMembers() || \current_user_can(Capabilities::PROGRAM_MANAGE);
    }

    private function renderDashboard(): string
    {
        $projects = \array_values(\array_filter(
            $this->projects->all(),
            static fn (Project $project): bool => $project->status->current()
        ));
        if ($projects === []) {
            return '<p>' . \esc_html__('There are no current projects.', 'orchestra-projects') . '</p>';
        }

        $html = '';
        foreach (\array_reverse($projects) as $project) {
            $html .= '<section class="orchestra-project">'
                . '<h2><a href="' . \esc_url(self::projectUrl($project->id)) . '">' . \esc_html($project->title)
                . '</a></h2>'
                . $this->renderFacts($project)
                . $this->renderOwnParticipation($project)
                . $this->renderOwnAssignments($project)
                . '</section>';
        }

        return $html;
    }

    private function renderProject(Project $project): string
    {
        $html = '<p><a href="' . \esc_url(self::projectUrl(null)) . '">&larr; '
            . \esc_html__('All projects', 'orchestra-projects') . '</a></p>'
            . '<h2>' . \esc_html($project->title) . '</h2>'
            . $this->renderFacts($project)
            . '<p>' . \esc_html__('Conductors', 'orchestra-projects') . ': '
            . \esc_html($this->names->list($this->staff->userIds($project->id, StaffRepository::CONDUCTOR))) . '<br>'
            . \esc_html__('Organizers', 'orchestra-projects') . ': '
            . \esc_html($this->names->list($this->staff->userIds($project->id, StaffRepository::ORGANIZER))) . '</p>'
            . \wp_kses_post(\wpautop($project->description));

        $html .= '<h3>' . \esc_html__('Program', 'orchestra-projects') . '</h3>';
        $pieceIds = $this->program->pieceIds($project->id);
        if ($pieceIds === []) {
            $html .= '<p>' . \esc_html__('The program has not been published yet.', 'orchestra-projects') . '</p>';
        } else {
            $html .= '<ol class="orchestra-project-program">';
            foreach ($pieceIds as $pieceId) {
                $piece = $this->library->piece($pieceId);
                if ($piece !== null) {
                    $html .= '<li>' . \esc_html($piece->title)
                        . ($piece->composer === '' ? '' : ' – ' . \esc_html($piece->composer)) . '</li>';
                }
            }
            $html .= '</ol>';
        }

        if (\current_user_can(Capabilities::PROGRAM_MANAGE)) {
            $html .= '<p><a href="' . \esc_url(ProjectsPage::url($project->id)) . '">'
                . \esc_html__('Manage project', 'orchestra-projects') . '</a></p>';
        }

        return $html . $this->renderOwnParticipation($project) . $this->renderOwnAssignments($project);
    }

    private function renderFacts(Project $project): string
    {
        $facts = [\esc_html($project->status->label())];
        if ($project->startDate !== null || $project->endDate !== null) {
            $facts[] = \esc_html(ProjectsPage::dateRange($project));
        }
        if ($project->concertDate !== null) {
            $facts[] = \esc_html__('Concert', 'orchestra-projects') . ': '
                . \esc_html(ProjectsPage::date($project->concertDate));
        }

        return '<p class="orchestra-project-facts">' . \implode(' · ', $facts) . '</p>';
    }

    private function renderOwnParticipation(Project $project): string
    {
        $participation = $this->participations->findFor($project->id, \get_current_user_id());
        if ($participation === null || !\current_user_can(Capabilities::PARTICIPATION_READ, $participation->id)) {
            return '';
        }

        $html = '<p class="orchestra-project-participation"><strong>'
            . \esc_html__('Your participation', 'orchestra-projects') . ':</strong> '
            . \esc_html($participation->status->label()) . '</p>';

        $canRespond = \current_user_can(Capabilities::PARTICIPATION_RESPOND, $participation->id)
            && ParticipationRules::responseRefusal(
                $project->status,
                $participation->status,
                ParticipationStatus::Accepted
            ) === null;
        if ($canRespond) {
            $accept = \__('Accept', 'orchestra-projects');
            $decline = \__('Decline', 'orchestra-projects');
            $accept = self::responseButton($participation, ParticipationStatus::Accepted, $accept);
            $decline = self::responseButton($participation, ParticipationStatus::Declined, $decline);
            $html .= '<p>' . $accept . ' ' . $decline
                . '</p><p class="description">'
                . \esc_html__('Your decision is final. To change it later, contact an organizer.', 'orchestra-projects')
                . '</p>';
        }

        return $html;
    }

    private function renderOwnAssignments(Project $project): string
    {
        if (!\current_user_can(Capabilities::ASSIGNMENT_READ)) {
            return '';
        }
        $assignments = $this->assignments->forProjectAndUser($project->id, \get_current_user_id());
        if ($assignments === []) {
            return '';
        }

        $order = \array_flip($this->program->pieceIds($project->id));
        \usort($assignments, static fn ($a, $b): int => ($order[$a->pieceId] ?? 0) <=> ($order[$b->pieceId] ?? 0));

        $html = '<h3>' . \esc_html__('Your parts', 'orchestra-projects') . '</h3>'
            . '<ul class="orchestra-project-assignments">';
        $partIds = [];
        foreach ($assignments as $assignment) {
            $part = $this->library->part($assignment->partId);
            $piece = $this->library->piece($assignment->pieceId);
            if ($part === null || $piece === null) {
                continue;
            }
            $partIds[] = $part->id;
            $html .= '<li>' . \esc_html($piece->title) . ': <strong>' . \esc_html($part->name) . '</strong></li>';
        }
        $html .= '</ul>';

        if ($partIds !== []) {
            $url = $this->library->bundleDownloadUrl($partIds, $project->title);
            $html .= '<p><a class="button" href="' . \esc_url($url) . '">'
                . \esc_html__('Download my sheet music', 'orchestra-projects') . '</a></p>';
        }

        return $html;
    }

    private static function responseButton(
        Participation $participation,
        ParticipationStatus $response,
        string $label,
    ): string {
        $confirm = $response === ParticipationStatus::Accepted
            ? \__('Accept to participate? Your decision is final.', 'orchestra-projects')
            : \__('Decline to participate? Your decision is final.', 'orchestra-projects');

        return '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '" style="display:inline"'
            . ' onsubmit="return confirm(' . \esc_attr((string) \wp_json_encode($confirm)) . ');">'
            . '<input type="hidden" name="action" value="' . ResponseController::ACTION . '">'
            . '<input type="hidden" name="participation" value="' . $participation->id . '">'
            . '<input type="hidden" name="response" value="' . $response->value . '">'
            . \wp_nonce_field(ResponseController::nonceAction($participation->id), '_wpnonce', true, false)
            . '<button type="submit">' . \esc_html($label) . '</button></form>';
    }

    private function renderNotices(): string
    {
        $html = '';
        foreach ($this->notices->take() as [$type, $message]) {
            $html .= '<p class="orchestra-projects-notice orchestra-projects-notice-' . \esc_attr($type) . '"'
                . ' role="status">'
                . \esc_html($message) . '</p>';
        }

        return $html;
    }

    private static function projectUrl(?int $projectId): string
    {
        $url = (string) \get_permalink();

        return $projectId === null
            ? \remove_query_arg(self::QUERY_PARAMETER, $url)
            : \add_query_arg(self::QUERY_PARAMETER, $projectId, $url);
    }
}
