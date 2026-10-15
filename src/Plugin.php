<?php

declare(strict_types=1);

namespace Orchestra\Projects;

use LogicException;
use Orchestra\Core\Authorization\CapabilityRegistry;
use Orchestra\Core\Email\EmailService;
use Orchestra\Core\Plugin as CorePlugin;
use Orchestra\Library\Plugin as LibraryPlugin;
use Orchestra\Members\MembersService;
use Orchestra\Members\Plugin as MembersPlugin;
use Orchestra\Projects\Admin\AssignmentsTab;
use Orchestra\Projects\Admin\EmailLinks;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Admin\Notices;
use Orchestra\Projects\Admin\ParticipationTab;
use Orchestra\Projects\Admin\ProgramTab;
use Orchestra\Projects\Admin\ProjectActions;
use Orchestra\Projects\Admin\ProjectEditor;
use Orchestra\Projects\Admin\ProjectsPage;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Database\Schema;
use Orchestra\Projects\Email\ProjectEmailGroups;
use Orchestra\Projects\Frontend\ProjectsShortcode;
use Orchestra\Projects\Frontend\ResponseController;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationService;
use Orchestra\Projects\Privacy\PersonalData;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Program\ProgramService;
use Orchestra\Projects\Project\ProjectCleanup;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatusService;
use Orchestra\Projects\Project\StaffRepository;
use wpdb;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class Plugin
{
    private const GITHUB_REPOSITORY = 'https://github.com/TheRacoonOfDeath/orchestra-projects/';

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
        \add_action('plugins_loaded', [$this, 'registerFeatures']);
    }

    /**
     * @internal Hooked to `plugins_loaded`, when the other Orchestra plugins are loaded if active.
     */
    public function registerFeatures(): void
    {
        if (
            !\class_exists(CapabilityRegistry::class)
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
        $names = new Names($members);
        $notices = new Notices();

        // The email service exists since orchestra-core 0.3.
        $email = \class_exists(EmailService::class) ? CorePlugin::instance()->email() : null;
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
        (new ResponseController($this->participations, $participation, $notices))->register();
        (new ProjectsShortcode(
            $this->projectRepository,
            $this->staff,
            $this->program,
            $this->participations,
            $this->assignments,
            $library,
            $names,
            $notices
        ))->register();

        if (\is_admin()) {
            $notices->register();
            (new ProjectEditor($this->projectRepository, $this->staff, $members, $notices))->register();
            (new ProjectActions(
                $this->projectRepository,
                $statusService,
                $programService,
                $this->participations,
                $participation,
                $assignmentService,
                $notices
            ))->register();
            (new ProjectsPage(
                $this->projectRepository,
                $this->staff,
                $this->participations,
                $names,
                new ProgramTab($this->program, $programService, $library),
                new ParticipationTab($this->participations, $this->assignments, $names),
                new AssignmentsTab(
                    $this->program,
                    $this->assignments,
                    $assignmentService,
                    $this->participations,
                    $library,
                    $names,
                    $emailLinks
                ),
                $emailLinks,
            ))->register();
        }
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
                'Orchestra Projects requires Orchestra Core, Orchestra Library and Orchestra Members to be active.',
                'orchestra-projects'
            )
            . '</p></div>';
    }

    private function registerUpdateChecker(): void
    {
        $updateChecker = PucFactory::buildUpdateChecker(
            self::GITHUB_REPOSITORY,
            $this->pluginFile,
            'orchestra-projects'
        );

        // The factory is typed to return any kind of update checker; branches and release
        // assets are only supported by the GitHub-based one.
        if (!\method_exists($updateChecker, 'setBranch') || !\method_exists($updateChecker, 'getVcsApi')) {
            return;
        }

        $updateChecker->setBranch('main');

        $vcsApi = $updateChecker->getVcsApi();
        if (\is_object($vcsApi) && \method_exists($vcsApi, 'enableReleaseAssets')) {
            $vcsApi->enableReleaseAssets();
        }
    }
}
