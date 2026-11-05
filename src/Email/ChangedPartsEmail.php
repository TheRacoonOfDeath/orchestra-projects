<?php

declare(strict_types=1);

namespace Orchestra\Projects\Email;

use Orchestra\Core\Ui\Pages;
use Orchestra\Email\Automatic\AutomaticEmail;
use Orchestra\Email\Automatic\AutomaticEmailRegistry;
use Orchestra\Email\EmailService;
use Orchestra\Library\LibraryService;
use Orchestra\Projects\Assignment\AssignmentChanges;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Assignment\PartChange;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Ui\ProjectsShortcode;
use Orchestra\Projects\Ui\ProjectView;

/**
 * The automatic email to members whose parts changed while the project is in Parts assigned, each
 * with only their own changes. Organizers change its text on the Email page.
 */
final class ChangedPartsEmail
{
    public const ID = 'orchestra_projects_changed_parts';

    public function __construct(
        private readonly EmailService $email,
        private readonly LibraryService $library,
        private readonly ProgramRepository $program,
        private readonly AssignmentRepository $assignments,
        private readonly AssignmentService $assignmentService,
    ) {
    }

    /**
     * Whether orchestra-email sends automatic emails (since 0.10).
     */
    public static function available(?EmailService $email): bool
    {
        return $email !== null && \class_exists(AutomaticEmail::class);
    }

    public function register(): void
    {
        \add_action(EmailService::REGISTER_AUTOMATIC_ACTION, [$this, 'registerEmail']);
    }

    /**
     * @internal Hooked to `orchestra_email_register_automatic`.
     */
    public function registerEmail(AutomaticEmailRegistry $registry): void
    {
        $registry->add(new AutomaticEmail(
            self::ID,
            \__('Changed parts', 'orchestra-projects'),
            \__(
                'Sent when conductors or organizers inform the members about parts changed after the parts were'
                    . ' assigned. Every member gets only their own changes.',
                'orchestra-projects'
            ),
            Capabilities::NOTIFICATION_EDIT,
            \__('Your parts in {project} have changed', 'orchestra-projects'),
            \__(
                '<p>Hello {name},</p><p>your parts in {project} have changed:</p>{changes}'
                    . '<p>All your parts and the sheet music are on the page of the project: {link}</p>',
                'orchestra-projects'
            ),
            [
                'name' => \__('The name of the member', 'orchestra-projects'),
                'project' => \__('The name of the project', 'orchestra-projects'),
                'changes' => \__('The list of the member\'s changed parts', 'orchestra-projects'),
                'link' => \__('The link to the parts of the project', 'orchestra-projects'),
            ]
        ));
    }

    /**
     * @param list<int> $userIds
     * @return list<int> who of them receives the email
     */
    public function recipients(array $userIds): array
    {
        return $this->email->automaticRecipients(self::ID, $userIds);
    }

    /**
     * Sends every affected member their changes.
     *
     * @param list<PartChange> $changes
     * @return int the number of members the email is sent to
     */
    public function send(Project $project, array $changes, int $senderId): int
    {
        $base = Pages::url(ProjectsShortcode::TAG);
        $link = $base === null
            ? \home_url('/')
            : ProjectsShortcode::urls($base)->project($project->id, ProjectView::ASSIGNMENTS);

        $values = [];
        foreach (AssignmentChanges::byUser($changes) as $userId => $own) {
            $user = \get_userdata($userId);
            if ($user === false) {
                continue;
            }
            $values[$userId] = [
                'name' => \esc_html($user->display_name),
                'project' => \esc_html($project->title),
                'changes' => $this->changesHtml($project, $own),
                'link' => '<a href="' . \esc_url($link) . '">' . \esc_html($link) . '</a>',
            ];
        }

        return $this->email->sendAutomatic(
            self::ID,
            $senderId,
            /* translators: %s: project name */
            \sprintf(\__('Changed parts: %s', 'orchestra-projects'), $project->title),
            $values
        );
    }

    /**
     * One member's changes as a list, a line per piece in program order. Inline styles, since the
     * list is also sent by email.
     *
     * @param list<PartChange> $changes of one member
     */
    public function changesHtml(Project $project, array $changes): string
    {
        $order = \array_flip($this->program->pieceIds($project->id));
        \usort(
            $changes,
            static fn (PartChange $a, PartChange $b): int
                => ($order[$a->pieceId] ?? PHP_INT_MAX) <=> ($order[$b->pieceId] ?? PHP_INT_MAX)
        );
        $new = $this->newAssignments($project, $changes);

        $html = '<ul style="margin:0 0 16px;padding-left:24px">';
        foreach ($changes as $change) {
            $piece = $this->library->piece($change->pieceId)->title ?? \__('A removed piece', 'orchestra-projects');
            $newPart = $change->newPartId === null ? '' : $this->partName($change->newPartId)
                . (isset($new[$change->pieceId]) ? ' (' . AssignmentService::newPartLabel() . ')' : '');
            $line = match (true) {
                $change->oldPartId === null => \sprintf(
                    /* translators: %s: part name */
                    \__('now %s', 'orchestra-projects'),
                    $newPart
                ),
                $change->newPartId === null => \sprintf(
                    /* translators: %s: part name */
                    \__('no longer %s', 'orchestra-projects'),
                    $this->partName($change->oldPartId)
                ),
                default => \sprintf(
                    /* translators: 1: previous part name, 2: new part name */
                    \__('%1$s → %2$s', 'orchestra-projects'),
                    $this->partName($change->oldPartId),
                    $newPart
                ),
            };
            $html .= '<li><strong>' . \esc_html($piece) . '</strong>: ' . \esc_html($line) . '</li>';
        }

        return $html . '</ul>';
    }

    private function partName(int $partId): string
    {
        return $this->library->part($partId)->name ?? \__('a removed part', 'orchestra-projects');
    }

    /**
     * @param list<PartChange> $changes of one member
     * @return array<int, true> piece ID => true where the member's new part is new for them
     */
    private function newAssignments(Project $project, array $changes): array
    {
        $userId = $changes[0]->userId ?? 0;
        $pieceIds = \array_map(static fn (PartChange $c): int => $c->pieceId, $changes);
        $new = $this->assignmentService->newParts($project->id, $pieceIds);

        $result = [];
        foreach ($this->assignments->forProjectAndUser($project->id, $userId) as $assignment) {
            if (isset($new[$assignment->id])) {
                $result[$assignment->pieceId] = true;
            }
        }

        return $result;
    }
}
