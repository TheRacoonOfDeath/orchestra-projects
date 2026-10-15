<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Core\Ui\Notices;
use Orchestra\Projects\Project\ProjectFormHandler;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Ui\Badges;
use Orchestra\Projects\Ui\ProjectFormView;
use WP_Post;

/**
 * The meta boxes for dates and staff on the project edit screen in wp-admin. They show the fields
 * of {@see ProjectFormView} and are saved by {@see ProjectFormHandler}, like the projects page.
 */
final class ProjectEditor
{
    private const NONCE_ACTION = 'orchestra_projects_project';
    private const NONCE_FIELD = 'orchestra_projects_project_nonce';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectFormView $form,
        private readonly ProjectFormHandler $handler,
    ) {
    }

    public function register(): void
    {
        \add_action('add_meta_boxes_' . ProjectPostType::NAME, [$this, 'addMetaBoxes']);
        \add_action('save_post_' . ProjectPostType::NAME, [$this, 'save'], 10, 2);
        \add_filter('parent_file', [$this, 'highlightMenu']);
    }

    /**
     * @internal Hooked to `add_meta_boxes_orchestra_project`.
     */
    public function addMetaBoxes(): void
    {
        $dates = \__('Dates and status', 'orchestra-projects');
        $staff = \__('Conductors and organizers', 'orchestra-projects');
        \add_meta_box('orchestra-projects-dates', $dates, [$this, 'renderDates'], null, 'side');
        \add_meta_box('orchestra-projects-staff', $staff, [$this, 'renderStaff']);
    }

    /**
     * @internal Meta box callback.
     */
    public function renderDates(WP_Post $post): void
    {
        \wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        $project = $this->projects->find($post->ID);
        $html = $this->form->dateFields($project);
        if ($project !== null) {
            $html .= '<p>' . Badges::project($project->status)
                . '</p><p><a class="orchestra-button orchestra-button--small" href="'
                . \esc_url(ProjectsPage::url($project->id)) . '">' . \esc_html__('Manage project', 'orchestra-projects')
                . '</a></p>';
        }

        echo '<div class="orchestra orchestra--admin">' . $html . '</div>'; // escaped by the view
    }

    /**
     * @internal Meta box callback.
     */
    public function renderStaff(WP_Post $post): void
    {
        echo '<div class="orchestra orchestra--admin">' . $this->form->staffFields($post->ID) . '</div>'; // escaped
    }

    /**
     * @internal Hooked to `save_post_orchestra_project`.
     */
    public function save(int $postId, WP_Post $post): void
    {
        $nonce = $_POST[self::NONCE_FIELD] ?? null;
        if (
            !\is_string($nonce)
            || \wp_verify_nonce(\sanitize_key($nonce), self::NONCE_ACTION) === false
            || \wp_is_post_revision($postId) !== false
            || \wp_is_post_autosave($postId) !== false
            || !\current_user_can('edit_post', $postId)
        ) {
            return;
        }

        /** @var array<mixed> $input */
        $input = \wp_unslash($_POST);
        Notices::errors($this->handler->save($postId, $input));
    }

    /**
     * Highlights the "Projects" menu on the project edit screens.
     *
     * @internal Hooked to `parent_file`.
     */
    public function highlightMenu(string $parentFile): string
    {
        $screen = \get_current_screen();

        return $screen !== null && $screen->post_type === ProjectPostType::NAME ? ProjectsPage::SLUG : $parentFile;
    }
}
