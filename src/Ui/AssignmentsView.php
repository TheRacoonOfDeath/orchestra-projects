<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Library\LibraryService;
use Orchestra\Library\Part\Part;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Assignment\Assignment;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Assignment\HistoryMark;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Export\AssignmentsPdfController;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;

/**
 * The parts of every piece in the program with their players; conductors assign members here.
 */
final class AssignmentsView
{
    public function __construct(
        private readonly ProgramRepository $program,
        private readonly AssignmentRepository $assignments,
        private readonly AssignmentService $assignmentService,
        private readonly ParticipationRepository $participations,
        private readonly LibraryService $library,
        private readonly Names $names,
    ) {
    }

    public function render(Project $project): string
    {
        $pieceIds = $this->program->pieceIds($project->id);
        if ($pieceIds === []) {
            return Html::empty(\__('Add pieces to the program first.', 'orchestra-projects'));
        }

        $html = AssignmentsPdfController::available()
            ? '<div class="orchestra-actions">' . Form::link(
                AssignmentsPdfController::url($project->id),
                \__('Download PDF', 'orchestra-projects'),
                'small'
            ) . '</div>'
            : '';
        $editable = $project->status->assignmentsEditable() && \current_user_can(Capabilities::ASSIGNMENT_MANAGE);
        if (!$project->status->assignmentsEditable()) {
            $html .= '<div class="orchestra-notice"><p>' . \esc_html__(
                'Assignments can only be changed while the project is open for participation or in preparation.',
                'orchestra-projects'
            ) . '</p></div>';
        }

        $byPart = [];
        foreach ($this->assignments->forProject($project->id) as $assignment) {
            $byPart[$assignment->partId][] = $assignment;
        }
        $new = $this->assignmentService->newParts($project->id, $pieceIds);
        $players = [];
        foreach ($this->participations->forProject($project->id, ParticipationStatus::Accepted) as $participation) {
            if ($participation->userId !== null) {
                $players[$participation->userId] = $this->names->of($participation->userId);
            }
        }
        \asort($players, SORT_NATURAL | SORT_FLAG_CASE);
        if ($editable && $players === []) {
            $html .= '<div class="orchestra-notice orchestra-notice--warning"><p>'
                . \esc_html__('Nobody has accepted to participate yet.', 'orchestra-projects') . '</p></div>';
        }

        $lastParts = $editable ? \count($this->assignmentService->lastPartAssignments($project)) : 0;
        if ($lastParts > 0) {
            $html .= '<div class="orchestra-actions">' . ProjectForms::button(
                $project->id,
                'assign_last',
                \__('Assign the parts played last time', 'orchestra-projects'),
                [],
                null,
                'primary small'
            ) . '<span class="orchestra-muted">' . \esc_html(\sprintf(
                /* translators: %d: number of parts */
                \_n(
                    'Assigns %d part: members who are not assigned in a piece yet get the part they played last'
                        . ' time. Members who never played the piece are not assigned.',
                    'Assigns %d parts: members who are not assigned in a piece yet get the part they played last'
                        . ' time. Members who never played the piece are not assigned.',
                    $lastParts,
                    'orchestra-projects'
                ),
                $lastParts
            )) . '</span></div>';
        }

        // The columns of all pieces line up.
        $html .= '<div class="orchestra-aligned-tables orchestra-stack">';
        foreach ($pieceIds as $pieceId) {
            $html .= $this->piece($project, $pieceId, $byPart, $players, $new, $editable);
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<int, list<Assignment>> $byPart
     * @param array<int, string> $players user ID => name of the accepted participants
     * @param array<int, true> $new IDs of the assignments to a part the member plays for the first time
     */
    private function piece(
        Project $project,
        int $pieceId,
        array $byPart,
        array $players,
        array $new,
        bool $editable,
    ): string {
        $piece = $this->library->piece($pieceId);
        $parts = $this->library->parts($pieceId);
        $title = \esc_html($piece->title ?? '#' . $pieceId);
        if ($parts === []) {
            return '<section class="orchestra-stack"><h3>' . $title . '</h3>'
                . Html::empty(\__('The piece has no parts.', 'orchestra-projects')) . '</section>';
        }

        $partOf = [];
        foreach ($parts as $part) {
            foreach ($byPart[$part->id] ?? [] as $assignment) {
                $partOf[$assignment->userId ?? 0] = $part->name;
            }
        }
        // Accepted participants without a part in this piece.
        $unassigned = \array_diff_key($players, $partOf);
        if ($project->status->assignmentsEditable() && $unassigned !== []) {
            $title .= ' ' . Html::badge(
                \__('At least one member has not been assigned a part yet', 'orchestra-projects'),
                'warning'
            );
        }
        $html = '<section class="orchestra-stack"><h3>' . $title . '</h3>';

        $headings = [\__('Part', 'orchestra-projects'), \__('Assigned', 'orchestra-projects')];
        if ($editable) {
            $headings[] = \__('Assign', 'orchestra-projects');
        }
        $rows = [];
        foreach ($parts as $part) {
            $assigned = $byPart[$part->id] ?? [];
            $row = ['<strong>' . \esc_html($part->name)
                . '</strong>', $this->assignedList($project, $assigned, $new, $editable)];
            if ($editable) {
                $row[] = $this->assignForm($project, $part, $unassigned);
            }
            $rows[] = $row;
        }

        return $html . Html::table($headings, $rows) . '</section>';
    }

    /**
     * @param list<Assignment> $assigned
     * @param array<int, true> $new IDs of the assignments to a part the member plays for the first time
     */
    private function assignedList(Project $project, array $assigned, array $new, bool $editable): string
    {
        if ($assigned === []) {
            return '<span class="orchestra-muted">—</span>';
        }

        $items = '';
        foreach ($assigned as $assignment) {
            $remove = $editable
                ? ProjectForms::button(
                    $project->id,
                    'unassign',
                    \__('Remove', 'orchestra-projects'),
                    ['assignment' => $assignment->id],
                    null,
                    'link danger'
                )
                : '';
            $items .= '<li class="orchestra-actions"><span>' . \esc_html($this->names->of($assignment->userId))
                . (isset($new[$assignment->id]) ? ' ' . Badges::newPart() : '')
                . '</span>' . $remove . '</li>';
        }

        return '<ul>' . $items . '</ul>';
    }

    /**
     * @param array<int, string> $candidates user ID => name of the accepted participants without a part in
     *     this piece; members who play another part are moved by removing them there first
     */
    private function assignForm(Project $project, Part $part, array $candidates): string
    {
        if ($candidates === []) {
            return '<span class="orchestra-muted">—</span>';
        }

        $marks = \current_user_can(Capabilities::ASSIGNMENT_HISTORY_READ)
            ? $this->assignmentService->historyMarks($project, $part, \array_keys($candidates))
            : [];

        $boxes = '';
        foreach ($candidates as $userId => $name) {
            $history = $marks[$userId] ?? [];
            $notes = \array_map(static fn (HistoryMark $mark): string => $mark->label(), $history);
            $boxes .= '<label class="orchestra-checkbox"><input type="checkbox" name="users[]" value="' . $userId . '"'
                . ($history === [] ? '' : ' data-history="' . \esc_attr(\implode(' ', \array_map(
                    static fn (HistoryMark $mark): string => $mark->value,
                    $history
                ))) . '"')
                . '> <span>' . \esc_html($name)
                . ($notes === []
                    ? ''
                    : ' <span class="orchestra-muted">— ' . \esc_html(\implode(', ', $notes)) . '</span>')
                . '</span></label>';
        }

        return '<details class="orchestra-disclosure"><summary>' . \esc_html__('Assign members', 'orchestra-projects')
            . ' <span class="orchestra-muted">(' . \count($candidates) . ')</span></summary>'
            . ProjectForms::open($project->id, 'assign', ['part' => $part->id], ['class' => 'orchestra-stack'])
            . '<div class="orchestra-checklist" id="orchestra-projects-part-' . $part->id . '" role="group"'
            . ' aria-label="' . \esc_attr__('Members', 'orchestra-projects') . '">' . $boxes . '</div>'
            . Form::submit(\__('Assign', 'orchestra-projects'), 'primary small') . '</form></details>';
    }
}
