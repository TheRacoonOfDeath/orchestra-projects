<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatus;

/**
 * The projects as cards, in two tabs: the active projects and the completed ones (`?orchestra_completed=1`).
 * Members see their response and parts; conductors and organizers also see drafts.
 */
final class ProjectListView
{
    public const COMPLETED_PARAMETER = 'orchestra_completed';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly MemberProjectView $memberView,
        private readonly ParticipationView $participationView,
    ) {
    }

    public function render(ProjectUrls $urls): string
    {
        $staff = \current_user_can(Capabilities::PROGRAM_MANAGE);
        $completed = ($_GET[self::COMPLETED_PARAMETER] ?? null) === '1';
        $projects = \array_values(\array_filter(
            $this->projects->all(),
            static fn (Project $project): bool => $completed
                ? $project->status === ProjectStatus::Completed
                : ($staff ? $project->status->active() : $project->status->current())
        ));

        $html = '';
        if (\current_user_can(Capabilities::PROJECT_MANAGE)) {
            $html .= '<div class="orchestra-toolbar"><span></span>'
                . Form::link($urls->edit(null), \__('New project', 'orchestra-projects'), 'primary') . '</div>';
        }
        $html .= Html::tabs(
            ['active' => \__('Active', 'orchestra-projects'), 'completed' => \__('Completed', 'orchestra-projects')],
            $completed ? 'completed' : 'active',
            static fn (string $tab): string => self::url($urls, $tab === 'completed')
        );
        if ($projects === []) {
            return $html . Html::empty($completed
                ? \__('There are no completed projects yet.', 'orchestra-projects')
                : \__('There are no current projects.', 'orchestra-projects'));
        }

        // Completed projects stay newest first, as the repository sorts them. Active ones show the
        // next concert on top, drafts after the running projects.
        if (!$completed) {
            \usort($projects, static fn (Project $a, Project $b): int => [self::group($a), $a->sortDate() ?? '9999']
                <=> [self::group($b), $b->sortDate() ?? '9999']);
        }

        $html .= '<div class="orchestra-cards">';
        foreach ($projects as $project) {
            $meta = '<span>' . \esc_html(Dates::range($project)) . '</span>';
            if ($project->concertDates !== []) {
                $meta .= '<span>' . \esc_html(Dates::concertsLabel($project)) . ': '
                    . \esc_html(Dates::concerts($project)) . '</span>';
            }
            if ($staff) {
                $meta .= '<span>' . \esc_html($this->participationView->summary($project)) . '</span>';
            }

            $html .= '<article class="orchestra-card"><div class="orchestra-toolbar"><h3 class="orchestra-card__title">'
                . '<a href="' . \esc_url($urls->project($project->id)) . '">' . \esc_html($project->title) . '</a></h3>'
                . Badges::project($project->status) . '</div><div class="orchestra-card__meta">' . $meta . '</div>'
                . $this->memberView->participation($project, false) . $this->memberView->parts($project)
                . '<div class="orchestra-card__actions">'
                . Form::link($urls->project($project->id), \__('Open', 'orchestra-projects'), 'small')
                . '</div></article>';
        }

        return $html . '</div>';
    }

    /**
     * The list of active or completed projects.
     */
    public static function url(ProjectUrls $urls, bool $completed): string
    {
        return $completed
            ? \add_query_arg(self::COMPLETED_PARAMETER, '1', $urls->list())
            : \remove_query_arg(self::COMPLETED_PARAMETER, $urls->list());
    }

    private static function group(Project $project): int
    {
        return $project->status === ProjectStatus::Draft ? 1 : 0;
    }
}
