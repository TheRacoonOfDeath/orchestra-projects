<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationService;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramService;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\Project\ProjectStatusService;

/**
 * Handles the forms of the project screens (admin-post.php?action=orchestra_projects).
 */
final class ProjectActions
{
    public const ACTION = 'orchestra_projects';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectStatusService $status,
        private readonly ProgramService $program,
        private readonly ParticipationRepository $participations,
        private readonly ParticipationService $participation,
        private readonly AssignmentService $assignments,
        private readonly Notices $notices,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public static function nonceAction(int $projectId): string
    {
        return self::ACTION . '_' . $projectId;
    }

    /**
     * @internal Hooked to `admin_post_orchestra_projects`.
     */
    public function handle(): never
    {
        $projectId = self::int('project');
        \check_admin_referer(self::nonceAction($projectId));
        $project = $this->projects->find($projectId);
        if ($project === null) {
            \wp_die(\esc_html__('The project does not exist.', 'orchestra-projects'), 404);
        }

        $operation = self::string('op');
        [$capability, $tab] = match ($operation) {
            'status' => [Capabilities::PROJECT_MANAGE, 'overview'],
            'program_add', 'program_remove', 'program_up', 'program_down' => [Capabilities::PROGRAM_MANAGE, 'program'],
            'participation' => [Capabilities::PARTICIPATION_MANAGE, 'participation'],
            'assign', 'unassign' => [Capabilities::ASSIGNMENT_MANAGE, 'assignments'],
            default => [null, 'overview'],
        };
        if ($capability === null || !\current_user_can($capability)) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-projects'), 403);
        }

        $errors = $this->execute($project, $operation);
        if ($errors === []) {
            $this->notices->success(\__('Saved.', 'orchestra-projects'));
        } else {
            $this->notices->errors($errors);
        }

        \wp_safe_redirect(ProjectsPage::url($project->id, $tab));
        exit;
    }

    /**
     * @return list<string> error messages
     */
    private function execute(Project $project, string $operation): array
    {
        $userId = \get_current_user_id();
        $error = match ($operation) {
            'status' => $this->changeStatus($project),
            'program_add' => $this->program->add($project, self::int('piece')),
            'program_remove' => $this->program->remove($project, self::int('piece'), self::flag('confirm')),
            'program_up' => $this->program->move($project, self::int('piece'), -1),
            'program_down' => $this->program->move($project, self::int('piece'), 1),
            'participation' => $this->changeParticipation($project, $userId),
            'unassign' => $this->assignments->unassign($project, self::int('assignment')),
            default => null,
        };
        if ($operation === 'assign') {
            $users = self::ints('users');

            return $this->assignments->assign($project, self::int('part'), $users, self::flag('move'), $userId);
        }

        return $error === null ? [] : [$error];
    }

    private function changeStatus(Project $project): ?string
    {
        $target = ProjectStatus::tryFrom(self::string('target'));

        return $target === null
            ? \__('Unknown status.', 'orchestra-projects')
            : $this->status->change($project, $target);
    }

    private function changeParticipation(Project $project, int $organizerId): ?string
    {
        $participation = $this->participations->find(self::int('participation'));
        $status = ParticipationStatus::tryFrom(self::string('status'));
        if ($participation === null || $participation->projectId !== $project->id || $status === null) {
            return \__('The participation does not exist.', 'orchestra-projects');
        }

        return $this->participation->change($participation, $status, $organizerId, self::flag('confirm'));
    }

    private static function int(string $key): int
    {
        $value = $_POST[$key] ?? null;

        return \is_string($value) ? \absint($value) : 0;
    }

    private static function string(string $key): string
    {
        $value = $_POST[$key] ?? null;

        return \is_string($value) ? \sanitize_key($value) : '';
    }

    private static function flag(string $key): bool
    {
        return ($_POST[$key] ?? null) === '1';
    }

    /**
     * @return list<int>
     */
    private static function ints(string $key): array
    {
        $values = $_POST[$key] ?? [];
        if (!\is_array($values)) {
            return [];
        }

        return \array_values(\array_filter(\array_map(
            static fn (mixed $value): int => \is_string($value) ? \absint($value) : 0,
            $values
        )));
    }
}
