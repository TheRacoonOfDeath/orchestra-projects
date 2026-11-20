<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Members\MembersService;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Export\ParticipantsExportController;
use Orchestra\Projects\Field\ProjectAnswers;
use Orchestra\Projects\Field\ProjectField;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationRules;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;

/**
 * Who accepted, declined or has not responded; organizers change decisions here. Every group can
 * be closed and opened and starts closed, because the lists are long. Inactive members are listed
 * only if they accepted, since that is the history of who took part.
 */
final class ParticipationView
{
    /**
     * `?orchestra_group=accepted` opens that group, which is otherwise closed: the forms of a group
     * return to it, so that it stays open after each change.
     */
    public const GROUP_PARAMETER = 'orchestra_group';

    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
        private readonly Names $names,
        private readonly MembersService $members,
        private readonly bool $exportAvailable,
        private readonly ?ProjectAnswers $answers = null,
    ) {
    }

    /**
     * "3 accepted, 1 declined, 5 pending"
     */
    public function summary(Project $project): string
    {
        $counts = \array_fill_keys(\array_map(static fn ($s) => $s->value, ParticipationStatus::cases()), 0);
        foreach ($this->listed($project) as $participation) {
            $counts[$participation->status->value]++;
        }

        return \sprintf(
            /* translators: 1: number accepted, 2: number declined, 3: number pending */
            \__('%1$d accepted, %2$d declined, %3$d pending', 'orchestra-projects'),
            $counts[ParticipationStatus::Accepted->value],
            $counts[ParticipationStatus::Declined->value],
            $counts[ParticipationStatus::Open->value]
        );
    }

    /**
     * The names of everyone who accepted, for the members' tab of the same name.
     */
    public function accepted(Project $project): string
    {
        $names = [];
        foreach ($this->participations->forProject($project->id, ParticipationStatus::Accepted) as $participation) {
            if ($participation->userId !== null) {
                $names[] = $this->names->of($participation->userId);
            }
        }
        if ($names === []) {
            return Html::empty(\__(
                'Nobody has accepted yet. Everyone who registers for the project is listed here.',
                'orchestra-projects'
            ));
        }
        \natcasesort($names);

        return '<p class="orchestra-muted">' . \esc_html(\sprintf(
            /* translators: %d: number of people */
            \_n('%d person has accepted.', '%d people have accepted.', \count($names), 'orchestra-projects'),
            \count($names)
        )) . '</p><ul class="orchestra-list">'
            . \implode('', \array_map(static fn (string $n): string => '<li>' . \esc_html($n) . '</li>', $names))
            . '</ul>';
    }

    public function render(Project $project): string
    {
        $all = $this->listed($project);
        if ($all === []) {
            return Html::empty($project->status === ProjectStatus::Draft
                ? \__('Members are asked to respond when the project is opened.', 'orchestra-projects')
                : \__('Nobody has been asked to respond.', 'orchestra-projects'));
        }

        $assignmentCounts = [];
        foreach ($this->assignments->forProject($project->id) as $assignment) {
            $key = $assignment->userId ?? 0;
            $assignmentCounts[$key] = ($assignmentCounts[$key] ?? 0) + 1;
        }
        $canManage = \current_user_can(Capabilities::PARTICIPATION_MANAGE);
        // The answers to the project fields are for organizers, like the comments.
        $fields = [];
        $answers = [];
        if ($canManage && $this->answers !== null) {
            $fields = $this->answers->fields($project);
            $answers = $fields === [] ? [] : $this->answers->forProject($project);
        }
        $requested = \is_string($_GET[self::GROUP_PARAMETER] ?? null) ? $_GET[self::GROUP_PARAMETER] : '';

        $html = '<p class="orchestra-muted">' . \esc_html($this->summary($project)) . '</p>'
            . $this->exportButton($project, $all)
            . '<div class="orchestra-aligned-tables orchestra-stack">';
        foreach ([ParticipationStatus::Accepted, ParticipationStatus::Open, ParticipationStatus::Declined] as $status) {
            $group = \array_values(\array_filter($all, static fn (Participation $p): bool => $p->status === $status));
            \usort($group, fn (Participation $a, Participation $b): int => \strcasecmp(
                $this->names->of($a->userId),
                $this->names->of($b->userId)
            ));

            $html .= '<details class="orchestra-group" id="' . self::anchor($status) . '"'
                . ($requested === $status->value ? ' open' : '') . '><summary>' . \esc_html($status->label())
                . ' <span class="orchestra-muted">(' . \count($group) . ')</span></summary>';
            if ($group === []) {
                $html .= '<p class="orchestra-muted">' . \esc_html__('Nobody', 'orchestra-projects') . '</p></details>';
                continue;
            }
            $returnTo = self::returnTo($status);

            $headings = [
                \__('Member', 'orchestra-projects'),
                \__('Last change', 'orchestra-projects'),
                \__('Assignments', 'orchestra-projects'),
            ];
            // The checkboxes belong to the form below the table, which changes the selected people.
            $bulkForm = 'orchestra-projects-bulk-' . $status->value;
            if ($canManage) {
                \array_unshift($headings, \__('Select', 'orchestra-projects'));
            }
            $withAnswers = $fields !== [] && $status === ParticipationStatus::Accepted;
            if ($withAnswers) {
                $headings[] = \__('Answers', 'orchestra-projects');
            }
            if ($canManage) {
                // The members' comments and the notes are for organizers only, not for conductors.
                $headings[] = \__('Comment and note', 'orchestra-projects');
                $headings[] = \__('Change', 'orchestra-projects');
            }
            $rows = [];
            foreach ($group as $participation) {
                $count = $participation->userId === null ? 0 : ($assignmentCounts[$participation->userId] ?? 0);
                $row = [
                    '<span class="orchestra-compact-title">' . \esc_html($this->names->of($participation->userId))
                        . '</span>',
                    $this->lastChange($participation),
                    '<span class="orchestra-compact-meta">' . \esc_html(\sprintf(
                        /* translators: %d: number of parts the member plays in the project */
                        \_n('%d part', '%d parts', $count, 'orchestra-projects'),
                        $count
                    )) . '</span>',
                ];
                if ($canManage) {
                    \array_unshift($row, '<input type="checkbox" name="participations[]" value="' . $participation->id
                        . '" form="' . $bulkForm . '" aria-label="' . \esc_attr(\sprintf(
                            /* translators: %s: member name */
                            \__('Select %s', 'orchestra-projects'),
                            $this->names->of($participation->userId)
                        )) . '">');
                }
                if ($withAnswers) {
                    $row[] = self::answers($fields, $answers[$participation->userId ?? 0] ?? []);
                }
                if ($canManage) {
                    // On phones, the comment, the note and the change are behind "Edit" (compact rows).
                    $row[] = '<div class="orchestra-desktop-only">'
                        . self::notes($project, $participation, 'desktop', $returnTo) . '</div>';
                    $row[] = '<div class="orchestra-desktop-only">'
                        . $this->changeForm($project, $participation, $count, $returnTo) . '</div>'
                        . '<div class="orchestra-phone-only"><details class="orchestra-disclosure"><summary>'
                        . \esc_html__('Edit', 'orchestra-projects') . '</summary><div class="orchestra-stack">'
                        . self::notes($project, $participation, 'phone', $returnTo)
                        . $this->changeForm($project, $participation, $count, $returnTo) . '</div></details></div>';
                }
                $rows[] = $row;
            }
            $html .= Html::table($headings, $rows, '', false, true);
            if ($canManage) {
                $html .= $this->bulkForm($project, $status, $bulkForm, $group, $assignmentCounts, $returnTo);
            }
            $html .= '</details>';
        }

        return $html . '</div>';
    }

    /**
     * A member's answers to the project fields, behind "N of M answered"; required ones not answered
     * yet are marked.
     *
     * @param list<ProjectField> $fields
     * @param array<string, string> $answers field ID => answer
     */
    private static function answers(array $fields, array $answers): string
    {
        $answered = 0;
        $missing = false;
        $items = [];
        foreach ($fields as $field) {
            $value = $answers[$field->id] ?? '';
            if ($value !== '') {
                $answered++;
            } elseif ($field->required) {
                $missing = true;
            }
            $items[] = [$field->label, FieldControl::value($field, $value)];
        }

        return '<details class="orchestra-disclosure"><summary>' . \esc_html(\sprintf(
            /* translators: 1: number of answered questions, 2: number of questions */
            \__('%1$d of %2$d answered', 'orchestra-projects'),
            $answered,
            \count($fields)
        )) . ($missing ? ' ' . Html::badge(\__('Required missing', 'orchestra-projects'), 'warning') : '')
            . '</summary>' . Html::details($items) . '</details>';
    }

    private static function anchor(ParticipationStatus $status): string
    {
        return 'orchestra-participation-' . $status->value;
    }

    /**
     * Where the forms of a group return to: this page with the group open, scrolled to it.
     *
     * @return array<string, string>
     */
    private static function returnTo(ParticipationStatus $status): array
    {
        $url = \add_query_arg(
            self::GROUP_PARAMETER,
            $status->value,
            \remove_query_arg(self::GROUP_PARAMETER, Redirect::currentUrl())
        );

        return [Redirect::FIELD => $url . '#' . self::anchor($status)];
    }

    /**
     * The participations shown and counted: inactive members only if they accepted.
     *
     * @return list<Participation>
     */
    private function listed(Project $project): array
    {
        return \array_values(\array_filter(
            $this->participations->forProject($project->id),
            fn (Participation $p): bool => $p->status === ParticipationStatus::Accepted
                || $p->userId === null
                || $this->members->isActive($p->userId)
        ));
    }

    /**
     * @param list<Participation> $all
     */
    private function exportButton(Project $project, array $all): string
    {
        $accepted = \array_filter(
            $all,
            static fn (Participation $p): bool => $p->status === ParticipationStatus::Accepted
        );
        if (!$this->exportAvailable || $accepted === [] || !\current_user_can(Capabilities::PARTICIPATION_EXPORT)) {
            return '';
        }

        return '<div class="orchestra-actions">' . Form::link(
            ParticipantsExportController::url($project->id),
            \__('Export accepted participants (CSV)', 'orchestra-projects'),
            'small'
        ) . '</div>';
    }

    /**
     * Changes the status of the selected people of a group at once. Like a single change, leaving
     * Accepted removes their assignments, after a confirmation.
     *
     * @param list<Participation> $group
     * @param array<int, int> $assignmentCounts user ID => number of assignments in the project
     * @param array<string, string> $returnTo
     */
    private function bulkForm(
        Project $project,
        ParticipationStatus $current,
        string $formId,
        array $group,
        array $assignmentCounts,
        array $returnTo,
    ): string {
        $attributes = ['id' => $formId, 'class' => 'orchestra-actions'];
        $fields = $returnTo;
        $withAssignments = \array_filter(
            $group,
            static fn (Participation $p): bool => ($assignmentCounts[$p->userId ?? 0] ?? 0) > 0
        );
        if ($current === ParticipationStatus::Accepted && $withAssignments !== []) {
            $fields['confirm'] = 1;
            $attributes['data-orchestra-confirm'] = \__(
                'Selected members who are no longer accepted lose their parts in this project. Continue?',
                'orchestra-projects'
            );
            $attributes['data-orchestra-confirm-field'] = 'status';
            $attributes['data-orchestra-confirm-unless'] = ParticipationStatus::Accepted->value;
        }

        $options = '';
        foreach (ParticipationStatus::cases() as $status) {
            if ($status !== $current) {
                $options .= '<option value="' . $status->value . '">' . \esc_html($status->label()) . '</option>';
            }
        }

        return ProjectForms::open($project->id, 'participation_bulk', $fields, $attributes)
            . '<span>' . \esc_html__('Selected:', 'orchestra-projects') . '</span>'
            . '<select name="status" aria-label="' . \esc_attr__('New status', 'orchestra-projects')
            . '" style="width:auto">' . $options . '</select>'
            . Form::submit(\__('Change selected', 'orchestra-projects'), 'small') . '</form>';
    }

    /**
     * The member's comment and the organizers' note, which organizers change here.
     *
     * @param string $variant Makes the IDs unique when the form is shown twice (desktop and phone)
     * @param array<string, string> $returnTo
     */
    private static function notes(
        Project $project,
        Participation $participation,
        string $variant,
        array $returnTo,
    ): string {
        $comment = $participation->memberComment === null
            ? '<span class="orchestra-muted">' . \esc_html__('No comment', 'orchestra-projects') . '</span>'
            : '<span>' . \nl2br(\esc_html($participation->memberComment)) . '</span>';
        $note = $participation->organizerNote;
        $id = 'orchestra-projects-note-' . $variant . '-' . $participation->id;

        return '<div class="orchestra-cell">' . $comment . '<details class="orchestra-disclosure"><summary>'
            . ($note === null
                ? \esc_html__('Add a note', 'orchestra-projects')
                : \esc_html__('Note:', 'orchestra-projects') . ' ' . \esc_html($note))
            . '</summary>'
            . ProjectForms::open(
                $project->id,
                'participation_note',
                ['participation' => $participation->id] + $returnTo,
                ['class' => 'orchestra-stack']
            )
            . '<textarea id="' . \esc_attr($id) . '" name="note" rows="2"'
            . ' aria-label="' . \esc_attr__('Note for the organizers', 'orchestra-projects') . '" maxlength="'
            . ParticipationRules::NOTE_MAX_LENGTH . '">' . \esc_textarea($note ?? '') . '</textarea>'
            . '<span class="orchestra-muted">' . \esc_html__('Only organizers see the note.', 'orchestra-projects')
            . '</span><div class="orchestra-actions">' . Form::submit(\__('Save note', 'orchestra-projects'), 'small')
            . '</div></form></details></div>';
    }

    /**
     * @param array<string, string> $returnTo
     */
    private function changeForm(
        Project $project,
        Participation $participation,
        int $assignmentCount,
        array $returnTo,
    ): string {
        $fields = ['participation' => $participation->id] + $returnTo;
        $attributes = ['class' => 'orchestra-actions'];
        if ($participation->status === ParticipationStatus::Accepted && $assignmentCount > 0) {
            $fields['confirm'] = 1;
            $attributes['data-orchestra-confirm'] = \sprintf(
                /* translators: %d: number of assignments */
                \__('The member\'s %d assignments in this project will be removed. Continue?', 'orchestra-projects'),
                $assignmentCount
            );
            $attributes['data-orchestra-confirm-field'] = 'status';
            $attributes['data-orchestra-confirm-unless'] = ParticipationStatus::Accepted->value;
        }

        $options = '';
        foreach (ParticipationStatus::cases() as $status) {
            $options .= '<option value="' . $status->value . '"'
                . \selected($status->value, $participation->status->value, false) . '>'
                . \esc_html($status->label()) . '</option>';
        }

        return ProjectForms::open($project->id, 'participation', $fields, $attributes)
            . '<select name="status" aria-label="' . \esc_attr__('New status', 'orchestra-projects')
            . '" style="width:auto">'
            . $options . '</select>' . Form::submit(\__('Change', 'orchestra-projects'), 'small') . '</form>';
    }

    /**
     * When the status was changed last, and by whom if it was not the member: e.g. an organizer
     * who registered them.
     */
    private function lastChange(Participation $participation): string
    {
        if ($participation->changedAt === null) {
            return '—';
        }

        // One block, so that it stays in one place when the table is stacked or compact.
        $html = '<div class="orchestra-compact-meta"><span>' . \esc_html(Dates::fromGmt($participation->changedAt))
            . '</span>';
        if ($participation->changedBy !== null && $participation->changedBy !== $participation->userId) {
            $html .= '<br><span class="orchestra-muted">' . \esc_html(\sprintf(
                /* translators: %s: name of the organizer */
                \__('entered by %s', 'orchestra-projects'),
                $this->names->of($participation->changedBy)
            )) . '</span>';
        }

        return $html . '</div>';
    }
}
