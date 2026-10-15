<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Html;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Project\ProjectRepository;

/**
 * `[orchestra_projects]`: everything about projects on a normal page. Members see their projects,
 * respond and download their sheet music; conductors and organizers manage projects here.
 *
 * `?orchestra_project=ID&orchestra_tab=…` shows a project, `&orchestra_edit=1` (or
 * `orchestra_project=new`) its form.
 */
final class ProjectsShortcode
{
    public const TAG = 'orchestra_projects';
    public const PROJECT_PARAMETER = 'orchestra_project';
    public const TAB_PARAMETER = 'orchestra_tab';
    public const EDIT_PARAMETER = 'orchestra_edit';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectListView $listView,
        private readonly ProjectView $projectView,
        private readonly ProjectFormView $formView,
    ) {
    }

    public function register(): void
    {
        \add_shortcode(self::TAG, [$this, 'render']);
    }

    public static function urls(string $base): ProjectUrls
    {
        return new ProjectUrls(
            static fn (): string => $base,
            static fn (int $projectId, string $tab): string => \add_query_arg(
                $tab === ProjectView::OVERVIEW
                    ? [self::PROJECT_PARAMETER => $projectId]
                    : [self::PROJECT_PARAMETER => $projectId, self::TAB_PARAMETER => $tab],
                $base
            ),
            static fn (?int $projectId): string => $projectId === null
                ? \add_query_arg(self::PROJECT_PARAMETER, 'new', $base)
                : \add_query_arg([self::PROJECT_PARAMETER => $projectId, self::EDIT_PARAMETER => 1], $base)
        );
    }

    /**
     * @internal Shortcode callback.
     */
    public function render(): string
    {
        if (!\is_user_logged_in()) {
            return Html::loginRequired(\__('Please log in to see the projects.', 'orchestra-projects'));
        }
        if (!\current_user_can(Capabilities::PROJECT_READ) && !\current_user_can(Capabilities::PROGRAM_MANAGE)) {
            return Html::notAllowed(\__('You are not allowed to see the projects.', 'orchestra-projects'));
        }

        $base = \remove_query_arg(
            [self::PROJECT_PARAMETER, self::TAB_PARAMETER, self::EDIT_PARAMETER],
            Redirect::currentUrl()
        );
        $urls = self::urls($base);
        $requested = \is_string($_GET[self::PROJECT_PARAMETER] ?? null) ? $_GET[self::PROJECT_PARAMETER] : '';

        if ($requested === 'new' && \current_user_can(Capabilities::PROJECT_MANAGE)) {
            return Html::page($this->formView->render(null, $urls));
        }
        if ($requested === '') {
            return Html::page($this->listView->render($urls));
        }

        $project = $this->projects->find(\absint($requested));
        if ($project === null || !ProjectView::canSee($project)) {
            return Html::page('<p><a href="' . \esc_url($base) . '">&larr; '
                . \esc_html__('All projects', 'orchestra-projects')
                . '</a></p>' . Html::empty(\__('The project does not exist.', 'orchestra-projects')));
        }
        if (($_GET[self::EDIT_PARAMETER] ?? '') === '1' && \current_user_can('edit_post', $project->id)) {
            return Html::page($this->formView->render($project, $urls));
        }

        $tab = \is_string($_GET[self::TAB_PARAMETER] ?? null) ? \sanitize_key($_GET[self::TAB_PARAMETER]) : '';

        return Html::page($this->projectView->render($project, $tab, $urls));
    }
}
