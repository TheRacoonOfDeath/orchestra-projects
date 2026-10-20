<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Core\Ui\Notices;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationService;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramService;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\Project\ProjectFormHandler;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\ProjectStatusService;
use Orchestra\Projects\Ui\Dates;
use Orchestra\Projects\Ui\ProjectFormView;
use Orchestra\Projects\Ui\ProjectsShortcode;

/**
 * Handles the project forms of the projects page and the wp-admin screens
 * (admin-post.php?action=orchestra_projects). Every form returns to the page it was sent from.
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
        private readonly ProjectFormHandler $formHandler,
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
        $operation = self::string('op');
        if ($operation === 'save') {
            $this->save($projectId);
        }

        $project = $this->projects->find($projectId);
        if ($project === null) {
            \wp_die(\esc_html__('The project does not exist.', 'orchestra-projects'), 404);
        }

        $capability = match ($operation) {
            'status', 'complete' => Capabilities::PROJECT_MANAGE,
            'program_add', 'program_remove', 'program_up', 'program_down' => Capabilities::PROGRAM_MANAGE,
            'participation', 'participation_note', 'participation_bulk' => Capabilities::PARTICIPATION_MANAGE,
            'assign', 'assign_last', 'unassign' => Capabilities::ASSIGNMENT_MANAGE,
            default => null,
        };
        if ($capability === null || !\current_user_can($capability)) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-projects'), 403);
        }

        if ($operation === 'assign_last') {
            $this->assignLastParts($project);
        }

        $errors = $this->execute($project, $operation);
        if ($errors === []) {
            Notices::success(\__('Saved.', 'orchestra-projects'));
        } else {
            Notices::errors($errors);
        }

        Redirect::back();
    }

    /**
     * Creates (project 0) or edits a project from the form of the projects page.
     */
    private function save(int $projectId): never
    {
        $post = $projectId > 0 ? $this->projects->find($projectId) : null;
        $allowed = $projectId === 0
            ? \current_user_can(Capabilities::PROJECT_MANAGE)
            : $post !== null && \current_user_can('edit_post', $projectId);
        if (!$allowed) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-projects'), 403);
        }

        /** @var array<mixed> $input */
        $input = \wp_unslash($_POST);
        $title = \is_string($input[ProjectFormView::FIELD_TITLE] ?? null)
            ? \sanitize_text_field($input[ProjectFormView::FIELD_TITLE])
            : '';
        if ($title === '') {
            Notices::error(\__('Please enter a name.', 'orchestra-projects'));
            Redirect::back();
        }
        $description = \is_string($input[ProjectFormView::FIELD_DESCRIPTION] ?? null)
            ? \wp_kses_post($input[ProjectFormView::FIELD_DESCRIPTION])
            : '';

        $data = ['post_title' => $title, 'post_content' => $description];
        $result = $projectId === 0
            ? \wp_insert_post($data + ['post_type' => ProjectPostType::NAME, 'post_status' => 'publish'], true)
            : \wp_update_post($data + ['ID' => $projectId], true);
        if (\is_wp_error($result)) {
            Notices::error($result->get_error_message());
            Redirect::back();
        }

        $errors = $this->formHandler->save($result, $input);
        Notices::errors($errors);
        if ($errors === []) {
            Notices::success(\__('The project has been saved.', 'orchestra-projects'));
        }

        // A new project is shown right away.
        Redirect::back($projectId === 0 ? [ProjectsShortcode::PROJECT_PARAMETER => $result] : []);
    }

    /**
     * @return list<string> error messages
     */
    private function execute(Project $project, string $operation): array
    {
        $userId = \get_current_user_id();
        $error = match ($operation) {
            'status' => $this->changeStatus($project),
            'complete' => $this->status->complete($project, Dates::today()),
            'program_add' => $this->program->add($project, self::int('piece')),
            'program_remove' => $this->program->remove($project, self::int('piece'), self::flag('confirm')),
            'program_up' => $this->program->move($project, self::int('piece'), -1),
            'program_down' => $this->program->move($project, self::int('piece'), 1),
            'participation' => $this->changeParticipation($project, $userId),
            'participation_note' => $this->changeNote($project),
            'participation_bulk' => $this->changeMany($project, $userId),
            'unassign' => $this->assignments->unassign($project, self::int('assignment')),
            default => null,
        };
        if ($operation === 'assign') {
            $users = self::ints('users');

            return $this->assignments->assign($project, self::int('part'), $users, self::flag('move'), $userId);
        }

        return $error === null ? [] : [$error];
    }

    private function assignLastParts(Project $project): never
    {
        $result = $this->assignments->assignLastParts($project, \get_current_user_id());
        if (\is_string($result)) {
            Notices::error($result);
        } elseif ($result === 0) {
            Notices::success(\__(
                'Nobody was assigned: no member without a part has played one of these pieces before.',
                'orchestra-projects'
            ));
        } else {
            Notices::success(\sprintf(
                /* translators: %d: number of assigned parts */
                \_n(
                    '%d part was assigned to the member who played it last time.',
                    '%d parts were assigned to the members who played them last time.',
                    $result,
                    'orchestra-projects'
                ),
                $result
            ));
        }

        Redirect::back();
    }

    private function changeStatus(Project $project): ?string
    {
        $target = ProjectStatus::tryFrom(self::string('target'));

        return $target === null
            ? \__('Unknown status.', 'orchestra-projects')
            : $this->status->change($project, $target);
    }

    /**
     * Changes the status of several participations; each one follows the rules of a single change.
     */
    private function changeMany(Project $project, int $organizerId): ?string
    {
        $status = ParticipationStatus::tryFrom(self::string('status'));
        $ids = self::ints('participations');
        if ($status === null || $ids === []) {
            return \__('Select at least one member and a status.', 'orchestra-projects');
        }

        $errors = [];
        foreach ($ids as $id) {
            $participation = $this->participations->find($id);
            if ($participation === null || $participation->projectId !== $project->id) {
                $errors[] = \__('The participation does not exist.', 'orchestra-projects');
                continue;
            }
            $error = $this->participation->change($participation, $status, $organizerId, self::flag('confirm'));
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors === [] ? null : \implode(' ', \array_unique($errors));
    }

    private function changeNote(Project $project): ?string
    {
        $participation = $this->participations->find(self::int('participation'));
        if ($participation === null || $participation->projectId !== $project->id) {
            return \__('The participation does not exist.', 'orchestra-projects');
        }
        $note = \is_string($_POST['note'] ?? null) ? \wp_unslash($_POST['note']) : '';
        $this->participation->note($participation, $note);

        return null;
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
