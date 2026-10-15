<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Core\Ui\Html;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Ui\ProjectListView;
use Orchestra\Projects\Ui\ProjectUrls;
use Orchestra\Projects\Ui\ProjectView;

/**
 * The "Projects" screen in wp-admin, showing the same views as `[orchestra_projects]`.
 * Projects are created and edited with the WordPress editor here.
 */
final class ProjectsPage
{
    public const SLUG = 'orchestra-projects';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectListView $listView,
        private readonly ProjectView $projectView,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'addMenu']);
    }

    public static function url(?int $projectId = null, string $tab = ProjectView::OVERVIEW): string
    {
        $args = ['page' => self::SLUG];
        if ($projectId !== null) {
            $args['project'] = $projectId;
            $args['tab'] = $tab;
        }

        return \add_query_arg($args, \admin_url('admin.php'));
    }

    public static function urls(): ProjectUrls
    {
        return new ProjectUrls(
            static fn (): string => self::url(),
            static fn (int $projectId, string $tab): string => self::url($projectId, $tab),
            static fn (?int $projectId): string => $projectId === null
                ? \admin_url('post-new.php?post_type=' . ProjectPostType::NAME)
                : (string) \get_edit_post_link($projectId, 'url')
        );
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
        if (!\array_key_exists(self::requestedTab(), ProjectView::tabs())) {
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
            $list = $this->listView->render(self::urls());
            echo Html::adminPage(\__('Projects', 'orchestra-projects'), $list); // escaped by the view
            return;
        }

        echo Html::adminPage(
            $project->title,
            $this->projectView->render($project, self::requestedTab(), self::urls(), false)
        ); // escaped by the views
    }

    private static function requestedProjectId(): int
    {
        return \absint(\is_string($_GET['project'] ?? null) ? $_GET['project'] : 0);
    }

    private static function requestedTab(): string
    {
        return \is_string($_GET['tab'] ?? null) ? \sanitize_key($_GET['tab']) : ProjectView::OVERVIEW;
    }
}
