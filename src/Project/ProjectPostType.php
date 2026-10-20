<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

use Orchestra\Projects\Capabilities;

/**
 * Projects are stored as the private custom post type `orchestra_project`:
 * title = name, content = description, meta = dates and project status.
 *
 * The project status replaces the WordPress post status: saved projects are always published,
 * and members see them depending on the project status.
 */
final class ProjectPostType
{
    public const NAME = 'orchestra_project';

    public const META_STATUS = 'orchestra_projects_status';
    public const META_START_DATE = 'orchestra_projects_start_date';
    public const META_END_DATE = 'orchestra_projects_end_date';
    /** One entry per concert; the name is kept from the time projects had one concert. */
    public const META_CONCERT_DATE = 'orchestra_projects_concert_date';

    public function register(): void
    {
        \add_action('init', [self::class, 'registerPostType']);
        \add_filter('wp_insert_post_data', [self::class, 'alwaysPublish']);
    }

    /**
     * @internal Hooked to `init`.
     */
    public static function registerPostType(): void
    {
        \register_post_type(self::NAME, [
            'labels' => [
                'name' => \__('Projects', 'orchestra-projects'),
                'singular_name' => \__('Project', 'orchestra-projects'),
                'add_new' => \__('Add Project', 'orchestra-projects'),
                'add_new_item' => \__('Add New Project', 'orchestra-projects'),
                'edit_item' => \__('Edit Project', 'orchestra-projects'),
                'new_item' => \__('New Project', 'orchestra-projects'),
                'not_found' => \__('No projects found.', 'orchestra-projects'),
                'not_found_in_trash' => \__('No projects found in Trash.', 'orchestra-projects'),
            ],
            'public' => false,
            'show_ui' => true,
            // The project list is the "Projects" screen of this plugin, see Admin\ProjectsPage.
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor', 'revisions'],
            'rewrite' => false,
            'query_var' => false,
            'map_meta_cap' => true,
            'capability_type' => ['orchestra_project', 'orchestra_projects'],
            'capabilities' => [
                'read' => Capabilities::PROJECT_READ,
                'create_posts' => Capabilities::PROJECT_MANAGE,
                'edit_posts' => Capabilities::PROJECT_MANAGE,
                'edit_others_posts' => Capabilities::PROJECT_MANAGE,
                'edit_published_posts' => Capabilities::PROJECT_MANAGE,
                'edit_private_posts' => Capabilities::PROJECT_MANAGE,
                'publish_posts' => Capabilities::PROJECT_MANAGE,
                'read_private_posts' => Capabilities::PROJECT_MANAGE,
                'delete_posts' => Capabilities::PROJECT_DELETE,
                'delete_others_posts' => Capabilities::PROJECT_DELETE,
                'delete_published_posts' => Capabilities::PROJECT_DELETE,
                'delete_private_posts' => Capabilities::PROJECT_DELETE,
            ],
        ]);
    }

    /**
     * @internal Hooked to `wp_insert_post_data`.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function alwaysPublish(array $data): array
    {
        $keep = ['auto-draft', 'trash', 'inherit', 'publish'];
        if (($data['post_type'] ?? null) === self::NAME && !\in_array($data['post_status'] ?? null, $keep, true)) {
            $data['post_status'] = 'publish';
        }

        return $data;
    }
}
