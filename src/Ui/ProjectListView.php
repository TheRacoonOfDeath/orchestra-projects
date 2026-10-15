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
 * The projects as cards: members see the current ones with their response and parts; conductors
 * and organizers see all projects.
 */
final class ProjectListView
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly MemberProjectView $memberView,
        private readonly ParticipationView $participationView,
    ) {
    }

    public function render(ProjectUrls $urls): string
    {
        $staff = \current_user_can(Capabilities::PROGRAM_MANAGE);
        $projects = \array_values(\array_filter(
            $this->projects->all(),
            static fn (Project $project): bool => $staff ? true : $project->status->current()
        ));

        $html = '';
        if (\current_user_can(Capabilities::PROJECT_MANAGE)) {
            $html .= '<div class="orchestra-toolbar"><span></span>'
                . Form::link($urls->edit(null), \__('New project', 'orchestra-projects'), 'primary') . '</div>';
        }
        if ($projects === []) {
            return $html . Html::empty(\__('There are no current projects.', 'orchestra-projects'));
        }

        // Current projects first, the next concert on top; past and draft projects after them.
        \usort($projects, static fn (Project $a, Project $b): int => [self::group($a), $a->sortDate() ?? '9999']
            <=> [self::group($b), $b->sortDate() ?? '9999']);

        $html .= '<div class="orchestra-cards">';
        foreach ($projects as $project) {
            $meta = '<span>' . \esc_html(Dates::range($project)) . '</span>';
            if ($project->concertDate !== null) {
                $meta .= '<span>' . \esc_html__('Concert', 'orchestra-projects') . ': '
                    . \esc_html(Dates::format($project->concertDate)) . '</span>';
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

    private static function group(Project $project): int
    {
        return match ($project->status) {
            ProjectStatus::Open, ProjectStatus::Preparation => 0,
            ProjectStatus::Draft => 1,
            ProjectStatus::Completed => 2,
            ProjectStatus::Archived => 3,
        };
    }
}
