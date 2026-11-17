<?php

declare(strict_types=1);

namespace Orchestra\Projects\Email;

use DateTimeImmutable;
use Orchestra\Email\Automatic\AutomaticEmail;
use Orchestra\Email\Automatic\AutomaticEmailRegistry;
use Orchestra\Email\EmailService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectPostType;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Project\ProjectStatus;
use Orchestra\Projects\Ui\Dates;

/**
 * The automatic email that reminds the players who have not responded yet, seven days before the
 * registration deadline of a project. WP-Cron checks the open projects every hour. The user who
 * created the project is its sender, so replies go to them.
 *
 * It is sent once per deadline, and only if the project was open and the deadline known on the day
 * of the reminder: a project opened, or a deadline set, with less than seven days left gets none.
 */
final class DeadlineReminder
{
    public const ID = 'orchestra_projects_deadline_reminder';
    public const CRON_HOOK = 'orchestra_projects_deadline_reminders';
    public const DAYS_BEFORE = 7;

    public function __construct(
        private readonly EmailService $email,
        private readonly ProjectRepository $projects,
        private readonly ParticipationRepository $participations,
    ) {
    }

    public function register(): void
    {
        \add_action(EmailService::REGISTER_AUTOMATIC_ACTION, [$this, 'registerEmail']);
        \add_action(self::CRON_HOOK, [$this, 'sendDue']);
        if (\wp_next_scheduled(self::CRON_HOOK) === false) {
            \wp_schedule_event(\time(), 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * @internal Hooked to `orchestra_email_register_automatic`.
     */
    public function registerEmail(AutomaticEmailRegistry $registry): void
    {
        $registry->add(new AutomaticEmail(
            self::ID,
            \__('Registration deadline reminder', 'orchestra-projects'),
            \sprintf(
                /* translators: %d: number of days */
                \__(
                    'Sent automatically %d days before the registration deadline of a project to all active'
                        . ' members who have not responded yet.',
                    'orchestra-projects'
                ),
                self::DAYS_BEFORE
            ),
            Capabilities::NOTIFICATION_EDIT,
            \__('Reminder: please respond to {project} by {deadline}', 'orchestra-projects'),
            \__(
                '<p>Hello {name},</p><p>you have not told us yet whether you take part in the project {project}'
                    . ' ({dates}). Please respond by {deadline}: {link}</p>',
                'orchestra-projects'
            ),
            [
                'name' => \__('The name of the member', 'orchestra-projects'),
                'project' => \__('The name of the project', 'orchestra-projects'),
                'dates' => \__('The dates of the project', 'orchestra-projects'),
                'deadline' => \__('The registration deadline', 'orchestra-projects'),
                'link' => \__('The link to the project, where members respond', 'orchestra-projects'),
            ]
        ));
    }

    /**
     * Sends the reminders that are due.
     *
     * @internal Hooked to the WP-Cron event `orchestra_projects_deadline_reminders`.
     */
    public function sendDue(): void
    {
        $today = Dates::today();
        foreach ($this->projects->all([ProjectStatus::Open]) as $project) {
            $due = self::due(
                $project,
                ProjectRepository::meta($project->id, ProjectPostType::META_OPENED_ON),
                ProjectRepository::meta($project->id, ProjectPostType::META_DEADLINE_SET_ON),
                ProjectRepository::meta($project->id, ProjectPostType::META_REMINDER_SENT),
                $today
            );
            if ($due) {
                // Remembered first, so that a failure never sends it twice.
                \update_post_meta($project->id, ProjectPostType::META_REMINDER_SENT, $project->registrationDeadline);
                $this->send($project);
            }
        }
    }

    /**
     * Whether the reminder of the project is due today: the project is open, the reminder day has come
     * and the deadline has not passed, the reminder was not sent for this deadline, and the project
     * was open and the deadline set on the reminder day already (projects opened before the opening
     * day was stored count as open).
     *
     * @param ?string $openedOn the day the project was last opened (Y-m-d)
     * @param ?string $setOn the day the deadline was set (Y-m-d)
     * @param ?string $sentFor the deadline the reminder was sent for
     * @param string $today Y-m-d in the site's time zone
     */
    public static function due(
        Project $project,
        ?string $openedOn,
        ?string $setOn,
        ?string $sentFor,
        string $today,
    ): bool {
        $deadline = $project->registrationDeadline;
        if ($project->status !== ProjectStatus::Open || $deadline === null || $sentFor === $deadline) {
            return false;
        }
        $day = self::reminderDay($deadline);

        return $day !== null
            && $today >= $day
            && $today <= $deadline
            && ($openedOn === null || $openedOn <= $day)
            && ($setOn === null || $setOn <= $day);
    }

    /**
     * The day of the reminder (Y-m-d) for a deadline, or null if the deadline is no date.
     */
    public static function reminderDay(string $deadline): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $deadline);

        return $date === false ? null : $date->modify('-' . self::DAYS_BEFORE . ' days')->format('Y-m-d');
    }

    /**
     * @return int the number of members emailed
     */
    public function send(Project $project): int
    {
        $link = StatusEmails::link($project);
        $values = [];
        foreach ($this->participations->forProject($project->id, ParticipationStatus::Open) as $participation) {
            $user = self::user($participation);
            if ($user === null) {
                continue;
            }
            $values[$user->ID] = [
                'name' => \esc_html($user->display_name),
                'project' => \esc_html($project->title),
                'dates' => \esc_html(Dates::range($project)),
                'deadline' => \esc_html(Dates::format($project->registrationDeadline)),
                'link' => '<a href="' . \esc_url($link) . '">' . \esc_html($link) . '</a>',
            ];
        }

        // The author of the project; 0 (no reply address) once that account is deleted.
        $author = \get_post_field('post_author', $project->id);

        return $values === [] ? 0 : $this->email->sendAutomatic(
            self::ID,
            \is_numeric($author) ? (int) $author : 0,
            /* translators: %s: project name */
            \sprintf(\__('Registration deadline: %s', 'orchestra-projects'), $project->title),
            $values
        );
    }

    private static function user(Participation $participation): ?\WP_User
    {
        $user = $participation->userId === null ? false : \get_userdata($participation->userId);

        return $user instanceof \WP_User ? $user : null;
    }
}
