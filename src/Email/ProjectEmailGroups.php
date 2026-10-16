<?php

declare(strict_types=1);

namespace Orchestra\Projects\Email;

use Orchestra\Email\EmailGroupRegistry;
use Orchestra\Email\EmailService;
use Orchestra\Email\RecipientGroup;
use Orchestra\Library\LibraryService;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectRepository;

/**
 * The project and assignment recipient groups for the email service of orchestra-core.
 *
 * Groups exist for the projects on the member page (open, in preparation or completed). Only used
 * with orchestra-core 0.3 or later, which provides the email service.
 */
final class ProjectEmailGroups
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProgramRepository $program,
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
        private readonly LibraryService $library,
    ) {
    }

    public function register(): void
    {
        \add_action(EmailService::REGISTER_GROUPS_ACTION, [$this, 'registerGroups']);
    }

    public static function participantsGroupId(int $projectId, ?ParticipationStatus $status = null): string
    {
        return 'orchestra_projects_' . $projectId . '_' . ($status === null ? 'all' : $status->value);
    }

    public static function pieceGroupId(int $projectId, int $pieceId): string
    {
        return 'orchestra_projects_' . $projectId . '_piece_' . $pieceId;
    }

    public static function partGroupId(int $projectId, int $partId): string
    {
        return 'orchestra_projects_' . $projectId . '_part_' . $partId;
    }

    /**
     * @internal Hooked to `orchestra_email_register_groups`.
     */
    public function registerGroups(EmailGroupRegistry $registry): void
    {
        foreach ($this->projects->all() as $project) {
            if ($project->status->current()) {
                $this->registerProject($registry, $project);
            }
        }
    }

    private function registerProject(EmailGroupRegistry $registry, Project $project): void
    {
        $participantGroups = [
            [null, \__('All participants (everyone asked)', 'orchestra-projects')],
            [ParticipationStatus::Accepted, \__('Accepted participants', 'orchestra-projects')],
            [ParticipationStatus::Open, \__('Pending participants (no response yet)', 'orchestra-projects')],
            [ParticipationStatus::Declined, \__('Declined participants', 'orchestra-projects')],
        ];
        foreach ($participantGroups as [$status, $label]) {
            $id = self::participantsGroupId($project->id, $status);
            $this->add($registry, $project, $id, $label, fn (): array => \array_map(
                static fn ($participation): ?int => $participation->userId,
                $this->participations->forProject($project->id, $status)
            ));
        }

        $byPart = [];
        foreach ($this->assignments->forProject($project->id) as $assignment) {
            $byPart[$assignment->partId][] = $assignment->userId;
        }
        foreach ($this->program->pieceIds($project->id) as $pieceId) {
            $piece = $this->library->piece($pieceId);
            if ($piece === null) {
                continue;
            }
            $parts = \array_values(\array_filter(
                $this->library->parts($pieceId),
                static fn ($part): bool => isset($byPart[$part->id])
            ));
            if ($parts === []) {
                continue;
            }

            /* translators: %s: piece title */
            $label = \sprintf(\__('Everyone playing %s', 'orchestra-projects'), $piece->title);
            $pieceUsers = \array_merge(...\array_map(static fn ($part): array => $byPart[$part->id], $parts));
            $id = self::pieceGroupId($project->id, $pieceId);
            $this->add($registry, $project, $id, $label, static fn (): array => $pieceUsers);

            foreach ($parts as $part) {
                /* translators: 1: piece title, 2: part name */
                $label = \sprintf(\__('%1$s: %2$s', 'orchestra-projects'), $piece->title, $part->name);
                $users = $byPart[$part->id];
                $id = self::partGroupId($project->id, $part->id);
                $this->add($registry, $project, $id, $label, static fn (): array => $users);
            }
        }
    }

    /**
     * @param \Closure(): array<mixed> $recipients
     */
    private function add(
        EmailGroupRegistry $registry,
        Project $project,
        string $id,
        string $label,
        \Closure $recipients,
    ): void {
        // The project is passed with the capability, so that rules per project can be added later.
        $registry->add(new RecipientGroup(
            $id,
            $label,
            $project->title,
            $recipients,
            Capabilities::EMAIL_SEND,
            [$project->id]
        ));
    }
}
