<?php

declare(strict_types=1);

namespace Orchestra\Projects;

use LogicException;
use Orchestra\Core\Authorization\CapabilityRegistry;
use Orchestra\Core\Ui\Html;
use Orchestra\Core\Ui\Menu;
use Orchestra\Core\Ui\MenuItem;
use Orchestra\Email\Plugin as EmailPlugin;
use Orchestra\Library\Plugin as LibraryPlugin;
use Orchestra\Members\MembersService;
use Orchestra\Members\Plugin as MembersPlugin;
use Orchestra\Projects\Admin\EmailLinks;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Admin\ProjectActions;
use Orchestra\Projects\Admin\ProjectEditor;
use Orchestra\Projects\Admin\ProjectsPage;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Database\Schema;
use Orchestra\Projects\Email\ProjectEmailGroups;
use Orchestra\Projects\Frontend\ResponseController;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationService;
use Orchestra\Projects\Privacy\PersonalData;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Program\ProgramService;
use Orchestra\Projects\Project\ProjectCleanup;
use Orchestra\Projects\Project\ProjectFormHandler;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatusService;
use Orchestra\Projects\Project\StaffRepository;
use Orchestra\Projects\Ui\AssignmentsView;
use Orchestra\Projects\Ui\MemberProjectView;
use Orchestra\Projects\Ui\ParticipationView;
use Orchestra\Projects\Ui\ProgramView;
use Orchestra\Projects\Ui\ProjectFormView;
use Orchestra\Projects\Ui\ProjectListView;
use Orchestra\Projects\Ui\ProjectsShortcode;
use Orchestra\Projects\Ui\ProjectView;
use wpdb;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class Plugin
{
    /**
     * Update metadata, attached to every GitHub release by build-plugin.sh. This download link
     * always points to the latest release and, unlike the GitHub API, has no rate limit for
     * requests without a token.
     */
    private const UPDATE_METADATA_URL =
        'https://github.com/TheRacoonOfDeath/orchestra-projects/releases/latest/download/update.json';

    private static ?self $instance = null;

    private readonly Schema $schema;
    private readonly ProjectRepository $projectRepository;
    private readonly StaffRepository $staff;
    private readonly ProgramRepository $program;
    private readonly ParticipationRepository $participations;
    private readonly AssignmentRepository $assignments;
    private readonly ProjectsService $projects;

    private function __construct(private readonly string $pluginFile, wpdb $wpdb)
    {
        $this->schema = new Schema($wpdb);
        $this->projectRepository = new ProjectRepository();
        $this->staff = new StaffRepository($wpdb, $this->schema);
        $this->program = new ProgramRepository($wpdb, $this->schema);
        $this->participations = new ParticipationRepository($wpdb, $this->schema);
        $this->assignments = new AssignmentRepository($wpdb, $this->schema);
        $this->projects = new ProjectsService(
            $this->projectRepository,
            $this->program,
            $this->participations,
            $this->assignments
        );
    }

    public static function boot(string $pluginFile): self
    {
        if (self::$instance === null) {
            $wpdb = $GLOBALS['wpdb'] ?? null;
            if (!$wpdb instanceof wpdb) {
                throw new LogicException('orchestra-projects requires a WordPress database connection.');
            }

            self::$instance = new self($pluginFile, $wpdb);
            self::$instance->register();
        }

        return self::$instance;
    }

    /**
     * The running plugin. Other plugins use it to access {@see ProjectsService}.
     */
    public static function instance(): self
    {
        return self::$instance ?? throw new LogicException('orchestra-projects is not loaded.');
    }

    public function projects(): ProjectsService
    {
        return $this->projects;
    }

    private function register(): void
    {
        $this->registerUpdateChecker();
        \add_action('init', [$this, 'loadTextDomain'], 0);
        \add_action('plugins_loaded', [$this, 'registerFeatures']);
    }

    /**
     * The language is chosen in orchestra-core (`Orchestra\Core\Ui\Language`).
     *
     * @internal Hooked to `init`, before anything is translated.
     */
    public function loadTextDomain(): void
    {
        $directory = \dirname(\plugin_basename($this->pluginFile)) . '/languages';
        \load_plugin_textdomain('orchestra-projects', false, $directory);
    }

    /**
     * @internal Hooked to `plugins_loaded`, when the other Orchestra plugins are loaded if active.
     */
    public function registerFeatures(): void
    {
        // The shared UI exists since orchestra-core 0.4.
        if (
            !\class_exists(CapabilityRegistry::class)
            || !\class_exists(Html::class)
            || !\class_exists(LibraryPlugin::class)
            || !\class_exists(MembersPlugin::class)
        ) {
            \add_action('admin_notices', [$this, 'renderMissingDependencyNotice']);
            return;
        }

        $library = LibraryPlugin::instance()->library();
        $members = MembersPlugin::instance()->members();
        $this->schema->migrateIfOutdated();

        $participation = new ParticipationService(
            $this->participations,
            $this->assignments,
            $this->projectRepository,
            $members
        );
        $programService = new ProgramService($this->program, $this->assignments, $library);
        $assignmentService = new AssignmentService(
            $this->assignments,
            $this->participations,
            $this->program,
            $this->projectRepository,
            $library
        );
        $statusService = new ProjectStatusService($this->projectRepository, $participation);
        $formHandler = new ProjectFormHandler($this->projectRepository, $this->staff, $members);
        $names = new Names($members);

        // orchestra-email is optional.
        $email = \class_exists(EmailPlugin::class) && EmailPlugin::running()
            ? EmailPlugin::instance()->email()
            : null;
        if ($email !== null) {
            (new ProjectEmailGroups(
                $this->projectRepository,
                $this->program,
                $this->participations,
                $this->assignments,
                $library
            ))->register();
        }
        $emailLinks = new EmailLinks($email);

        \add_action(
            'orchestra_core_register_capabilities',
            fn (CapabilityRegistry $registry) => Capabilities::register(
                $registry,
                fn (int $participationId): ?int => $this->participations->find($participationId)?->userId
            )
        );
        (new ProjectPostType())->register();
        (new ProjectCleanup(
            $this->projectRepository,
            $this->staff,
            $this->program,
            $this->participations,
            $this->assignments
        ))->register();
        $this->registerParticipationTriggers($participation);
        (new PersonalData($this->projectRepository, $this->participations, $this->assignments, $library))->register();

        // The views are shared by the projects page and the wp-admin screens.
        $memberView = new MemberProjectView($this->participations, $this->assignments, $this->program, $library);
        $participationView = new ParticipationView($this->participations, $this->assignments, $names);
        $listView = new ProjectListView($this->projectRepository, $memberView, $participationView);
        $formView = new ProjectFormView($this->staff, $members);
        $projectView = new ProjectView(
            $this->staff,
            $names,
            $memberView,
            new ProgramView($this->program, $programService, $library),
            $participationView,
            new AssignmentsView(
                $this->program,
                $this->assignments,
                $assignmentService,
                $this->participations,
                $library,
                $names,
                $emailLinks
            ),
            $emailLinks
        );

        (new ResponseController($this->participations, $participation))->register();
        (new ProjectActions(
            $this->projectRepository,
            $statusService,
            $programService,
            $this->participations,
            $participation,
            $assignmentService,
            $formHandler
        ))->register();
        (new ProjectsShortcode($this->projectRepository, $listView, $projectView, $formView))->register();
        \add_action(Menu::REGISTER_ACTION, [self::class, 'registerMenuItem']);

        if (\is_admin()) {
            (new ProjectEditor($this->projectRepository, $formView, $formHandler))->register();
            (new ProjectsPage($this->projectRepository, $listView, $projectView))->register();
        }
    }

    /**
     * @internal Hooked to `orchestra_core_register_menu_items`.
     */
    public static function registerMenuItem(Menu $menu): void
    {
        $menu->add(new MenuItem(
            ProjectsShortcode::TAG,
            \__('Projects', 'orchestra-projects'),
            \__('Respond to projects, see your parts and download your sheet music.', 'orchestra-projects'),
            static fn (int $userId): bool => \user_can($userId, Capabilities::PROJECT_READ)
                || \user_can($userId, Capabilities::PROGRAM_MANAGE),
            20
        ));
    }

    /**
     * New, reactivated and promoted players are asked to respond to the open projects.
     */
    private function registerParticipationTriggers(ParticipationService $participation): void
    {
        \add_action(MembersService::ACTION_ACTIVATED, [$participation, 'createForUser']);
        \add_action('set_user_role', static fn (int $userId) => $participation->createForUser($userId));
        \add_action('add_user_role', static fn (int $userId) => $participation->createForUser($userId));
    }

    /**
     * @internal Hooked to `admin_notices`.
     */
    public function renderMissingDependencyNotice(): void
    {
        echo '<div class="notice notice-error"><p>'
            . \esc_html__(
                'Orchestra Projects requires Orchestra Core 0.4, Orchestra Library and Orchestra Members to be active.',
                'orchestra-projects'
            )
            . '</p></div>';
    }

    private function registerUpdateChecker(): void
    {
        PucFactory::buildUpdateChecker(self::UPDATE_METADATA_URL, $this->pluginFile, 'orchestra-projects');
    }
}
