<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Export\ParticipantsExportController;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationRules;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;

/**
 * Who accepted, declined or has not responded; organizers change decisions here.
 */
final class ParticipationView
{
    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
        private readonly Names $names,
        private readonly bool $exportAvailable,
    ) {
    }

    /**
     * "3 accepted, 1 declined, 5 pending"
     */
    public function summary(Project $project): string
    {
        $counts = \array_fill_keys(\array_map(static fn ($s) => $s->value, ParticipationStatus::cases()), 0);
        foreach ($this->participations->forProject($project->id) as $participation) {
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
        $all = $this->participations->forProject($project->id);
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

        $html = '<p class="orchestra-muted">' . \esc_html($this->summary($project)) . '</p>'
            . $this->exportButton($project, $all)
            . '<div class="orchestra-aligned-tables orchestra-stack">';
        foreach ([ParticipationStatus::Accepted, ParticipationStatus::Open, ParticipationStatus::Declined] as $status) {
            $group = \array_values(\array_filter($all, static fn (Participation $p): bool => $p->status === $status));
            \usort($group, fn (Participation $a, Participation $b): int => \strcasecmp(
                $this->names->of($a->userId),
                $this->names->of($b->userId)
            ));

            $html .= '<h3>' . \esc_html($status->label()) . ' <span class="orchestra-muted">(' . \count($group)
                . ')</span></h3>';
            if ($group === []) {
                $html .= '<p class="orchestra-muted">' . \esc_html__('Nobody', 'orchestra-projects') . '</p>';
                continue;
            }

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
            if ($canManage) {
                // The members' comments and the notes are for organizers only, not for conductors.
                $headings[] = \__('Comment and note', 'orchestra-projects');
                $headings[] = \__('Change', 'orchestra-projects');
            }
            $rows = [];
            foreach ($group as $participation) {
                $count = $participation->userId === null ? 0 : ($assignmentCounts[$participation->userId] ?? 0);
                $row = [
                    \esc_html($this->names->of($participation->userId)),
                    $this->lastChange($participation),
                    (string) $count,
                ];
                if ($canManage) {
                    \array_unshift($row, '<input type="checkbox" name="participations[]" value="' . $participation->id
                        . '" form="' . $bulkForm . '" aria-label="' . \esc_attr(\sprintf(
                            /* translators: %s: member name */
                            \__('Select %s', 'orchestra-projects'),
                            $this->names->of($participation->userId)
                        )) . '">');
                }
                if ($canManage) {
                    $row[] = self::notes($project, $participation);
                    $row[] = $this->changeForm($project, $participation, $count);
                }
                $rows[] = $row;
            }
            $html .= Html::table($headings, $rows);
            if ($canManage) {
                $html .= $this->bulkForm($project, $status, $bulkForm, $group, $assignmentCounts);
            }
        }

        return $html . '</div>';
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
     */
    private function bulkForm(
        Project $project,
        ParticipationStatus $current,
        string $formId,
        array $group,
        array $assignmentCounts,
    ): string {
        $attributes = ['id' => $formId, 'class' => 'orchestra-actions'];
        $fields = [];
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
     */
    private static function notes(Project $project, Participation $participation): string
    {
        $comment = $participation->memberComment === null
            ? '<span class="orchestra-muted">' . \esc_html__('No comment', 'orchestra-projects') . '</span>'
            : '<span>' . \nl2br(\esc_html($participation->memberComment)) . '</span>';
        $note = $participation->organizerNote;
        $id = 'orchestra-projects-note-' . $participation->id;

        return '<div class="orchestra-cell">' . $comment . '<details class="orchestra-disclosure"><summary>'
            . ($note === null
                ? \esc_html__('Add a note', 'orchestra-projects')
                : \esc_html__('Note:', 'orchestra-projects') . ' ' . \esc_html($note))
            . '</summary>'
            . ProjectForms::open($project->id, 'participation_note', ['participation' => $participation->id], [
                'class' => 'orchestra-stack',
            ])
            . '<textarea id="' . \esc_attr($id) . '" name="note" rows="2"'
            . ' aria-label="' . \esc_attr__('Note for the organizers', 'orchestra-projects') . '" maxlength="'
            . ParticipationRules::NOTE_MAX_LENGTH . '">' . \esc_textarea($note ?? '') . '</textarea>'
            . '<span class="orchestra-muted">' . \esc_html__('Only organizers see the note.', 'orchestra-projects')
            . '</span><div class="orchestra-actions">' . Form::submit(\__('Save note', 'orchestra-projects'), 'small')
            . '</div></form></details></div>';
    }

    private function changeForm(Project $project, Participation $participation, int $assignmentCount): string
    {
        $fields = ['participation' => $participation->id];
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

        $html = \esc_html(Dates::fromGmt($participation->changedAt));
        if ($participation->changedBy !== null && $participation->changedBy !== $participation->userId) {
            $html .= '<br><span class="orchestra-muted">' . \esc_html(\sprintf(
                /* translators: %s: name of the organizer */
                \__('entered by %s', 'orchestra-projects'),
                $this->names->of($participation->changedBy)
            )) . '</span>';
        }

        return $html;
    }
}
