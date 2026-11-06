<?php

declare(strict_types=1);

namespace Orchestra\Projects\Email;

use Orchestra\Core\Ui\Pages;
use Orchestra\Email\Automatic\AutomaticEmail;
use Orchestra\Email\Automatic\AutomaticEmailRegistry;
use Orchestra\Email\EmailService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\Ui\Dates;
use Orchestra\Projects\Ui\ProjectsShortcode;
use Orchestra\Projects\Ui\ProjectView;

/**
 * The automatic emails sent when organizers move a draft to Open for Participation (to the players
 * who have not responded yet) or a project from Preparation to Parts assigned (to the accepted participants), if they
 * tick "Inform the members by email". Organizers change the texts on the Email page.
 */
final class StatusEmails
{
    public const OPEN_ID = 'orchestra_projects_open';
    public const PARTS_ASSIGNED_ID = 'orchestra_projects_parts_assigned';

    public function __construct(
        private readonly EmailService $email,
        private readonly ParticipationRepository $participations,
    ) {
    }

    /**
     * Whether orchestra-email sends automatic emails (since 0.10).
     */
    public static function available(?EmailService $email): bool
    {
        return ChangedPartsEmail::available($email);
    }

    /**
     * Whether moving a project from one status to the other can inform the members: only the forward
     * moves to Open for Participation and to Parts assigned, not the moves back to them.
     */
    public static function informs(ProjectStatus $from, ProjectStatus $to): bool
    {
        return ($from === ProjectStatus::Draft && $to === ProjectStatus::Open)
            || ($from === ProjectStatus::Preparation && $to === ProjectStatus::PartsAssigned);
    }

    public function register(): void
    {
        \add_action(EmailService::REGISTER_AUTOMATIC_ACTION, [$this, 'registerEmails']);
    }

    /**
     * @internal Hooked to `orchestra_email_register_automatic`.
     */
    public function registerEmails(AutomaticEmailRegistry $registry): void
    {
        $placeholders = [
            'name' => \__('The name of the member', 'orchestra-projects'),
            'project' => \__('The name of the project', 'orchestra-projects'),
            'dates' => \__('The dates of the project', 'orchestra-projects'),
        ];
        $registry->add(new AutomaticEmail(
            self::OPEN_ID,
            \__('Project open for participation', 'orchestra-projects'),
            \__(
                'Sent when organizers move a draft to "Open for Participation" and choose to inform the'
                    . ' members. It goes to all active members who have not responded yet.',
                'orchestra-projects'
            ),
            Capabilities::NOTIFICATION_EDIT,
            \__('{project} is open for participation', 'orchestra-projects'),
            \__(
                '<p>Hello {name},</p><p>the project {project} ({dates}) is open for participation.</p>'
                    . '<p>Please tell us whether you take part: {link}</p>',
                'orchestra-projects'
            ),
            $placeholders + ['link' => \__('The link to the project, where members respond', 'orchestra-projects')]
        ));
        $registry->add(new AutomaticEmail(
            self::PARTS_ASSIGNED_ID,
            \__('Parts assigned', 'orchestra-projects'),
            \__(
                'Sent when organizers move a project from "Preparation" to "Parts assigned" and choose to'
                    . ' inform the members. It goes to all members who accepted the project.',
                'orchestra-projects'
            ),
            Capabilities::NOTIFICATION_EDIT,
            \__('Your parts in {project} are assigned', 'orchestra-projects'),
            \__(
                '<p>Hello {name},</p><p>the parts in {project} ({dates}) are assigned.</p>'
                    . '<p>Your parts and the sheet music are on the page of the project: {link}</p>',
                'orchestra-projects'
            ),
            $placeholders + ['link' => \__('The link to the parts of the project', 'orchestra-projects')]
        ));
    }

    /**
     * @return list<int> the users the email of the status is meant for, before inactive and opted-out
     *     members are left out
     */
    public function candidates(Project $project, ProjectStatus $status): array
    {
        $participationStatus = match ($status) {
            ProjectStatus::Open => ParticipationStatus::Open,
            ProjectStatus::PartsAssigned => ParticipationStatus::Accepted,
            default => null,
        };
        if ($participationStatus === null) {
            return [];
        }

        return \array_values(\array_filter(\array_map(
            static fn (Participation $participation): ?int => $participation->userId,
            $this->participations->forProject($project->id, $participationStatus)
        )));
    }

    /**
     * Sends the email of the project's status and remembers that it was sent.
     *
     * @return array{sent: int, candidates: int}
     */
    public function send(Project $project, int $senderId): array
    {
        $id = match ($project->status) {
            ProjectStatus::Open => self::OPEN_ID,
            ProjectStatus::PartsAssigned => self::PARTS_ASSIGNED_ID,
            default => null,
        };
        if ($id === null) {
            return ['sent' => 0, 'candidates' => 0];
        }

        $link = self::link($project);
        $values = [];
        foreach ($this->candidates($project, $project->status) as $userId) {
            $user = \get_userdata($userId);
            if ($user === false) {
                continue;
            }
            $values[$userId] = [
                'name' => \esc_html($user->display_name),
                'project' => \esc_html($project->title),
                'dates' => \esc_html(Dates::range($project)),
                'link' => '<a href="' . \esc_url($link) . '">' . \esc_html($link) . '</a>',
            ];
        }

        $sent = $values === [] ? 0 : $this->email->sendAutomatic(
            $id,
            $senderId,
            /* translators: 1: project status, 2: project name */
            \sprintf(\__('%1$s: %2$s', 'orchestra-projects'), $project->status->label(), $project->title),
            $values
        );
        $this->markInformed($project->id, $project->status);

        return ['sent' => $sent, 'candidates' => \count($values)];
    }

    /**
     * Whether the members were informed by email when the project reached the status before.
     */
    public function informedBefore(int $projectId, ProjectStatus $status): bool
    {
        return \in_array($status->value, $this->informed($projectId), true);
    }

    private function markInformed(int $projectId, ProjectStatus $status): void
    {
        if (!$this->informedBefore($projectId, $status)) {
            \update_post_meta(
                $projectId,
                ProjectPostType::META_STATUS_EMAILS,
                [...$this->informed($projectId), $status->value]
            );
        }
    }

    /**
     * @return list<string> status values
     */
    private function informed(int $projectId): array
    {
        $stored = \get_post_meta($projectId, ProjectPostType::META_STATUS_EMAILS, true);

        return \is_array($stored) ? \array_values(\array_filter($stored, 'is_string')) : [];
    }

    private static function link(Project $project): string
    {
        $base = Pages::url(ProjectsShortcode::TAG);
        if ($base === null) {
            return \home_url('/');
        }

        return ProjectsShortcode::urls($base)->project(
            $project->id,
            $project->status === ProjectStatus::PartsAssigned ? ProjectView::ASSIGNMENTS : ProjectView::OVERVIEW
        );
    }
}
