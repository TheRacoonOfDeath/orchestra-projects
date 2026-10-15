<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Library\LibraryService;
use Orchestra\Library\Part\Part;
use Orchestra\Projects\Assignment\Assignment;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Email\ProjectEmailGroups;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;

final class AssignmentsTab
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

    public function render(Project $project): void
    {
        $pieceIds = $this->program->pieceIds($project->id);
        if ($pieceIds === []) {
            echo '<p>' . \esc_html__('Add pieces to the program first.', 'orchestra-projects') . '</p>';
            return;
        }

        $editable = $project->status->assignmentsEditable() && \current_user_can(Capabilities::ASSIGNMENT_MANAGE);
        if (!$project->status->assignmentsEditable()) {
            echo '<p>' . \esc_html__(
                'Assignments can only be changed while the project is open for participation or in preparation.',
                'orchestra-projects'
            ) . '</p>';
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
            echo '<p>' . \esc_html__('Nobody has accepted to participate yet.', 'orchestra-projects') . '</p>';
        }

        foreach ($pieceIds as $pieceId) {
            $piece = $this->library->piece($pieceId);
            $parts = $this->library->parts($pieceId);
            echo '<h2>' . \esc_html($piece->title ?? '#' . $pieceId) . '</h2>';
            if ($parts === []) {
                echo '<p>' . \esc_html__('The piece has no parts.', 'orchestra-projects') . '</p>';
                continue;
            }

            $partOf = [];
            foreach ($parts as $part) {
                foreach ($byPart[$part->id] ?? [] as $assignment) {
                    $partOf[$assignment->userId ?? 0] = $part->name;
                }
            }

            echo '<table class="wp-list-table widefat striped"><thead><tr>'
                . '<th scope="col" style="width:20%">' . \esc_html__('Part', 'orchestra-projects') . '</th>'
                . '<th scope="col">' . \esc_html__('Assigned', 'orchestra-projects') . '</th>'
                . ($editable
                    ? '<th scope="col" style="width:40%">' . \esc_html__('Assign', 'orchestra-projects') . '</th>'
                    : '')
                . '</tr></thead><tbody>';
            foreach ($parts as $part) {
                $assigned = $byPart[$part->id] ?? [];
                echo '<tr><td>' . \esc_html($part->name) . '</td><td>'
                    . $this->assignedList($project, $assigned, $editable) . '</td>';
                if ($editable) {
                    echo '<td>' . $this->assignForm($project, $part, $assigned, $players, $partOf) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';

            $assignedParts = \array_filter($parts, static fn (Part $part): bool => isset($byPart[$part->id]));
            $pieceHasAssignments = $assignedParts !== [];
            if ($pieceHasAssignments) {
                echo '<p>' . $this->emailLinks->buttons($project, [
                    ProjectEmailGroups::pieceGroupId($project->id, $pieceId)
                        => \__('Email everyone playing this piece', 'orchestra-projects'),
                ]) . '</p>'; // escaped
            }
        }
    }

    /**
     * @param list<Assignment> $assigned
     */
    private function assignedList(Project $project, array $assigned, bool $editable): string
    {
        if ($assigned === []) {
            return '—';
        }

        $items = [];
        foreach ($assigned as $assignment) {
            $item = \esc_html($this->names->of($assignment->userId));
            if ($editable) {
                $item .= ' ' . Form::button(
                    $project->id,
                    'unassign',
                    '×',
                    ['assignment' => $assignment->id],
                    null,
                    'button-link button-link-delete'
                );
            }
            $items[] = '<li>' . $item . '</li>';
        }

        return '<ul style="margin:0">' . \implode('', $items) . '</ul>';
    }

    /**
     * @param list<Assignment> $assigned
     * @param array<int, string> $players user ID => name of the accepted participants
     * @param array<int, string> $partOf user ID => the part of this piece they are assigned to
     */
    private function assignForm(Project $project, Part $part, array $assigned, array $players, array $partOf): string
    {
        $assignedIds = \array_map(static fn (Assignment $a): ?int => $a->userId, $assigned);
        $candidates = \array_diff_key($players, \array_flip(\array_filter($assignedIds, \is_int(...))));
        if ($candidates === []) {
            return '—';
        }

        $marks = \current_user_can(Capabilities::ASSIGNMENT_HISTORY_READ)
            ? $this->assignmentService->historyMarks($project, $part, \array_keys($candidates))
            : [];
        $moveMessage = \__(
            'Some selected members already play another part of this piece. Move them to this part?',
            'orchestra-projects'
        );
        $selectId = 'orchestra-projects-part-' . $part->id;

        $html = Form::open(
            $project->id,
            'assign',
            ['part' => $part->id, 'move' => 0],
            null,
            "var moving = Array.from(this.elements['users[]'].selectedOptions).some(function (o) {"
                . ' return o.dataset.current; }); if (!moving) { return true; }'
                . ' if (!confirm(' . Form::jsString($moveMessage) . ')) { return false; }'
                . ' this.move.value = 1; return true;'
        );
        $html .= '<label class="screen-reader-text" for="' . $selectId . '">'
            . \esc_html__('Members', 'orchestra-projects') . '</label>'
            . '<select multiple id="' . $selectId . '" name="users[]" size="' . \min(6, \count($candidates))
            . '" style="min-width:16em">';
        foreach ($candidates as $userId => $name) {
            $notes = [];
            if (isset($marks[$userId])) {
                $notes[] = $marks[$userId]->label();
            }
            if (isset($partOf[$userId])) {
                /* translators: %s: part name */
                $notes[] = \sprintf(\__('currently %s', 'orchestra-projects'), $partOf[$userId]);
            }
            $html .= '<option value="' . $userId . '"'
                . (isset($marks[$userId]) ? ' data-history="' . \esc_attr($marks[$userId]->value) . '"' : '')
                . (isset($partOf[$userId]) ? ' data-current="' . \esc_attr($partOf[$userId]) . '"' : '')
                . '>' . \esc_html($name . ($notes === [] ? '' : ' — ' . \implode(', ', $notes))) . '</option>';
        }

        return $html . '</select> <button type="submit" class="button">' . \esc_html__('Assign', 'orchestra-projects')
            . '</button></form>';
    }
}
