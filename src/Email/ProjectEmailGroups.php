<?php

declare(strict_types=1);

namespace Orchestra\Projects\Email;

use Orchestra\Email\EmailGroupRegistry;
use Orchestra\Email\EmailService;
use Orchestra\Email\RecipientGroup;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectRepository;

/**
 * The project recipient groups for the email service of orchestra-email: the accepted and pending
 * participants of the projects that are open or in preparation (also when they are over but not
 * completed yet), the next project first. There are no groups per piece or part.
 */
final class ProjectEmailGroups
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ParticipationRepository $participations,
    ) {
    }

    public function register(): void
    {
        \add_action(EmailService::REGISTER_GROUPS_ACTION, [$this, 'registerGroups']);
    }

    /**
     * @param ParticipationStatus|null $status null for the accepted and pending participants together
     */
    public static function participantsGroupId(int $projectId, ?ParticipationStatus $status = null): string
    {
        // "all" is kept from the time the group included those who declined, so that links still work.
        return 'orchestra_projects_' . $projectId . '_' . ($status === null ? 'all' : $status->value);
    }

    /**
     * @internal Hooked to `orchestra_email_register_groups`.
     */
    public function registerGroups(EmailGroupRegistry $registry): void
    {
        foreach (self::byStartDate($this->projects->all()) as $project) {
            if ($project->status->current()) {
                $this->registerProject($registry, $project);
            }
        }
    }

    /**
     * The next project first, projects without a start date last.
     *
     * @param list<Project> $projects
     * @return list<Project>
     */
    public static function byStartDate(array $projects): array
    {
        \usort($projects, static fn (Project $a, Project $b): int => [$a->startDate ?? '9999', $a->id]
            <=> [$b->startDate ?? '9999', $b->id]);

        return $projects;
    }

    private function registerProject(EmailGroupRegistry $registry, Project $project): void
    {
        // Only those who accepted or have not responded yet; those who declined are not emailed.
        $participantGroups = [
            [null, \__('Accepted and pending participants', 'orchestra-projects')],
            [ParticipationStatus::Accepted, \__('Accepted participants', 'orchestra-projects')],
            [ParticipationStatus::Open, \__('Pending participants (no response yet)', 'orchestra-projects')],
        ];
        foreach ($participantGroups as [$status, $label]) {
            $statuses = $status === null ? [ParticipationStatus::Accepted, ParticipationStatus::Open] : [$status];
            $id = self::participantsGroupId($project->id, $status);
            $this->add($registry, $project, $id, $label, fn (): array => \array_map(
                static fn (Participation $participation): ?int => $participation->userId,
                \array_filter(
                    $this->participations->forProject($project->id),
                    static fn (Participation $p): bool => \in_array($p->status, $statuses, true)
                )
            ));
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
