<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Core\Authorization\Roles;
use Orchestra\Members\MembersService;
use Orchestra\Projects\Project\ProjectDates;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\StaffRepository;
use WP_Post;

/**
 * The meta boxes for dates and staff on the project edit screen.
 */
final class ProjectEditor
{
    private const NONCE_ACTION = 'orchestra_projects_project';
    private const NONCE_FIELD = 'orchestra_projects_project_nonce';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly StaffRepository $staff,
        private readonly MembersService $members,
        private readonly Notices $notices,
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
        $dates = \__('Dates', 'orchestra-projects');
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
        $fields = [
            ProjectPostType::META_START_DATE => \__('Start date', 'orchestra-projects'),
            ProjectPostType::META_END_DATE => \__('End date', 'orchestra-projects'),
            ProjectPostType::META_CONCERT_DATE => \__('Concert date', 'orchestra-projects'),
        ];
        foreach ($fields as $key => $label) {
            $value = \get_post_meta($post->ID, $key, true);
            \printf(
                '<p><label for="%1$s">%2$s</label><br><input type="date" id="%1$s" name="%1$s" value="%3$s"></p>',
                \esc_attr($key),
                \esc_html($label),
                \esc_attr(\is_string($value) ? $value : '')
            );
        }

        $project = $this->projects->find($post->ID);
        if ($project !== null) {
            \printf(
                '<p>%s: <strong>%s</strong></p><p><a class="button" href="%s">%s</a></p>',
                \esc_html__('Status', 'orchestra-projects'),
                \esc_html($project->status->label()),
                \esc_url(ProjectsPage::url($project->id)),
                \esc_html__('Manage project', 'orchestra-projects')
            );
        }
    }

    /**
     * @internal Meta box callback.
     */
    public function renderStaff(WP_Post $post): void
    {
        echo '<p class="description">'
            . \esc_html__(
                'Shown on the project page. Every conductor and organizer has their rights in all projects.',
                'orchestra-projects'
            )
            . '</p>';

        $groups = [
            StaffRepository::CONDUCTOR => [\__('Conductors', 'orchestra-projects'), Roles::CONDUCTOR],
            StaffRepository::ORGANIZER => [\__('Organizers', 'orchestra-projects'), Roles::ORGANIZER],
        ];
        foreach ($groups as $staffRole => [$label, $role]) {
            $selected = $this->staff->userIds($post->ID, $staffRole);
            echo '<fieldset><legend><strong>' . \esc_html($label) . '</strong></legend>';
            $candidates = $this->members->members([$role]);
            if ($candidates === []) {
                echo '<p>' . \esc_html__('Nobody has this role yet.', 'orchestra-projects') . '</p>';
            }
            foreach ($candidates as $member) {
                \printf(
                    '<label style="display:block"><input type="checkbox" name="orchestra_projects_staff[%s][]"'
                        . ' value="%d"%s> %s</label>',
                    \esc_attr($staffRole),
                    $member->id,
                    \checked(\in_array($member->id, $selected, true), true, false),
                    \esc_html($member->displayName)
                );
            }
            echo '</fieldset>';
        }
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

        $dates = ProjectDates::fromInput(
            \wp_unslash($_POST[ProjectPostType::META_START_DATE] ?? ''),
            \wp_unslash($_POST[ProjectPostType::META_END_DATE] ?? ''),
            \wp_unslash($_POST[ProjectPostType::META_CONCERT_DATE] ?? '')
        );
        if ($dates->errors === []) {
            $this->projects->saveDates($postId, $dates);
        } else {
            $this->notices->errors($dates->errors);
        }

        $submitted = $_POST['orchestra_projects_staff'] ?? [];
        $submitted = \is_array($submitted) ? $submitted : [];
        $allowed = [
            StaffRepository::CONDUCTOR => Roles::CONDUCTOR,
            StaffRepository::ORGANIZER => Roles::ORGANIZER,
        ];
        foreach ($allowed as $staffRole => $role) {
            $candidates = \array_map(static fn ($member): int => $member->id, $this->members->members([$role]));
            $ids = \is_array($submitted[$staffRole] ?? null) ? $submitted[$staffRole] : [];
            $ids = \array_map('absint', \array_filter($ids, \is_scalar(...)));
            $this->staff->replace($postId, $staffRole, \array_values(\array_intersect($ids, $candidates)));
        }
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
