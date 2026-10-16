<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Library\LibraryService;
use Orchestra\Library\Part\Part;
use Orchestra\Projects\Admin\EmailLinks;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Assignment\Assignment;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Assignment\HistoryMark;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Email\ProjectEmailGroups;
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
        private readonly EmailLinks $emailLinks,
    ) {
    }

    public function render(Project $project): string
    {
        $pieceIds = $this->program->pieceIds($project->id);
        if ($pieceIds === []) {
            return Html::empty(\__('Add pieces to the program first.', 'orchestra-projects'));
        }

        $html = '';
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
            $html .= $this->piece($project, $pieceId, $byPart, $players, $editable);
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<int, list<Assignment>> $byPart
     * @param array<int, string> $players user ID => name of the accepted participants
     */
    private function piece(Project $project, int $pieceId, array $byPart, array $players, bool $editable): string
    {
        $piece = $this->library->piece($pieceId);
        $parts = $this->library->parts($pieceId);
        $title = \esc_html($piece->title ?? '#' . $pieceId);

        $assignedParts = \array_filter($parts, static fn (Part $part): bool => isset($byPart[$part->id]));
        $email = $assignedParts === [] ? '' : $this->emailLinks->buttons($project, [
            ProjectEmailGroups::pieceGroupId($project->id, $pieceId) => \__('Email the players', 'orchestra-projects'),
        ]);
        $html = '<section class="orchestra-stack"><div class="orchestra-toolbar"><h3>' . $title . '</h3>' . $email
            . '</div>';
        if ($parts === []) {
            return $html . Html::empty(\__('The piece has no parts.', 'orchestra-projects')) . '</section>';
        }

        $partOf = [];
        foreach ($parts as $part) {
            foreach ($byPart[$part->id] ?? [] as $assignment) {
                $partOf[$assignment->userId ?? 0] = $part->name;
            }
        }

        $headings = [\__('Part', 'orchestra-projects'), \__('Assigned', 'orchestra-projects')];
        if ($editable) {
            $headings[] = \__('Assign', 'orchestra-projects');
        }
        $rows = [];
        foreach ($parts as $part) {
            $assigned = $byPart[$part->id] ?? [];
            $row = ['<strong>' . \esc_html($part->name)
                . '</strong>', $this->assignedList($project, $assigned, $editable)];
            if ($editable) {
                $row[] = $this->assignForm($project, $part, $assigned, $players, $partOf);
            }
            $rows[] = $row;
        }

        return $html . Html::table($headings, $rows) . '</section>';
    }

    /**
     * @param list<Assignment> $assigned
     */
    private function assignedList(Project $project, array $assigned, bool $editable): string
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
            $items .= '<li class="orchestra-actions">' . \esc_html($this->names->of($assignment->userId)) . $remove
                . '</li>';
        }

        return '<ul>' . $items . '</ul>';
    }

    /**
     * @param list<Assignment> $assigned
     * @param array<int, string> $players
     * @param array<int, string> $partOf user ID => the part of this piece they play
     */
    private function assignForm(Project $project, Part $part, array $assigned, array $players, array $partOf): string
    {
        $assignedIds = \array_filter(
            \array_map(static fn (Assignment $a): ?int => $a->userId, $assigned),
            \is_int(...)
        );
        $candidates = \array_diff_key($players, \array_flip($assignedIds));
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
            if (isset($partOf[$userId])) {
                /* translators: %s: part name */
                $notes[] = \sprintf(\__('currently %s', 'orchestra-projects'), $partOf[$userId]);
            }
            $boxes .= '<label class="orchestra-checkbox"><input type="checkbox" name="users[]" value="' . $userId . '"'
                . ($history === [] ? '' : ' data-history="' . \esc_attr(\implode(' ', \array_map(
                    static fn (HistoryMark $mark): string => $mark->value,
                    $history
                ))) . '"')
                . (isset($partOf[$userId]) ? ' data-current="' . \esc_attr($partOf[$userId]) . '"' : '')
                . '> <span>' . \esc_html($name)
                . ($notes === []
                    ? ''
                    : ' <span class="orchestra-muted">— ' . \esc_html(\implode(', ', $notes)) . '</span>')
                . '</span></label>';
        }

        return '<details class="orchestra-disclosure"><summary>' . \esc_html__('Assign members', 'orchestra-projects')
            . ' <span class="orchestra-muted">(' . \count($candidates) . ')</span></summary>'
            . ProjectForms::open($project->id, 'assign', ['part' => $part->id, 'move' => 0], [
                'class' => 'orchestra-stack',
                'data-orchestra-move-confirm' => \__(
                    'Some selected members already play another part of this piece. Move them to this part?',
                    'orchestra-projects'
                ),
            ])
            . '<div class="orchestra-checklist" id="orchestra-projects-part-' . $part->id . '" role="group"'
            . ' aria-label="' . \esc_attr__('Members', 'orchestra-projects') . '">' . $boxes . '</div>'
            . Form::submit(\__('Assign', 'orchestra-projects'), 'primary small') . '</form></details>';
    }
}
