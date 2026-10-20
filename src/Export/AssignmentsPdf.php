<?php

declare(strict_types=1);

namespace Orchestra\Projects\Export;

use Orchestra\Core\Pdf\Pdf;
use Orchestra\Library\LibraryService;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Assignment\AssignmentMatrix;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Ui\Dates;

/**
 * The assignments of a project as a PDF: every piece of the program with its parts and players,
 * then on a page of its own the parts by member.
 */
final class AssignmentsPdf
{
    public function __construct(
        private readonly ProgramRepository $program,
        private readonly AssignmentRepository $assignments,
        private readonly AssignmentService $assignmentService,
        private readonly LibraryService $library,
        private readonly Names $names,
        private readonly ParticipationRepository $participations,
    ) {
    }

    public function html(Project $project): string
    {
        $pieceIds = $this->program->pieceIds($project->id);
        // Members who play a part for the first time are marked with an asterisk, explained below.
        $new = $this->assignmentService->newParts($project->id, $pieceIds);
        $players = [];
        $partNames = [];
        foreach ($this->assignments->forProject($project->id) as $assignment) {
            $players[$assignment->partId][] = $this->names->of($assignment->userId)
                . (isset($new[$assignment->id]) ? '*' : '');
        }

        $facts = [Dates::range($project)];
        if ($project->concertDates !== []) {
            $facts[] = Dates::concertsLabel($project) . ': ' . Dates::concerts($project);
        }
        $body = '<p class="muted">' . \esc_html(\implode(' · ', $facts)) . '</p>';

        if ($pieceIds === []) {
            $body .= '<p>' . \esc_html__('The program has no pieces yet.', 'orchestra-projects') . '</p>';
        }
        foreach ($pieceIds as $position => $pieceId) {
            $piece = $this->library->piece($pieceId);
            $body .= '<h2>' . ($position + 1) . '. ' . \esc_html($piece->title ?? '#' . $pieceId)
                . ($piece === null || $piece->composer === ''
                    ? ''
                    : ' <span class="muted">(' . \esc_html($piece->composer) . ')</span>')
                . '</h2>';

            $parts = $this->library->parts($pieceId);
            if ($parts === []) {
                $body .= '<p class="muted">' . \esc_html__('The piece has no parts.', 'orchestra-projects') . '</p>';
                continue;
            }
            $rows = '';
            foreach ($parts as $part) {
                $partNames[$part->id] = $part->name;
                $names = $players[$part->id] ?? [];
                \natcasesort($names);
                $rows .= '<tr><td class="part">' . \esc_html($part->name) . '</td><td>'
                    . ($names === [] ? '<span class="muted">—</span>' : \esc_html(\implode(', ', $names)))
                    . '</td></tr>';
            }
            $body .= '<table><thead><tr><th class="part">' . \esc_html__('Part', 'orchestra-projects') . '</th><th>'
                . \esc_html__('Players', 'orchestra-projects') . '</th></tr></thead><tbody>' . $rows
                . '</tbody></table>';
        }
        if ($pieceIds !== []) {
            $body .= $this->byMember($project, $pieceIds, $partNames, $new);
        }
        if ($new !== []) {
            $body .= '<p class="muted legend">' . \esc_html__(
                '* plays this part for the first time',
                'orchestra-projects'
            ) . '</p>';
        }

        return Pdf::document(
            \sprintf(
                /* translators: %s: project name */
                \__('Assignments: %s', 'orchestra-projects'),
                $project->title
            ),
            $body,
            '.part { width: 32%; } .legend { margin-top: 6mm; }'
                . ' .by-member { page-break-before: always; } .matrix th, .matrix td { font-size: 8pt; }'
        );
    }

    /**
     * The parts by member: a row per member, a column per piece; new parts have an asterisk.
     *
     * @param list<int> $pieceIds
     * @param array<int, string> $partNames
     * @param array<int, true> $new
     */
    private function byMember(Project $project, array $pieceIds, array $partNames, array $new): string
    {
        $accepted = [];
        foreach ($this->participations->forProject($project->id, ParticipationStatus::Accepted) as $participation) {
            if ($participation->userId !== null) {
                $accepted[$participation->userId] = $this->names->of($participation->userId);
            }
        }
        \asort($accepted, SORT_NATURAL | SORT_FLAG_CASE);
        $rows = AssignmentMatrix::rows(
            $accepted,
            $pieceIds,
            $this->assignments->forProject($project->id),
            $partNames,
            $new,
            fn (int $userId): string => $this->names->of($userId)
        );
        if ($rows === []) {
            return '';
        }

        $head = '<th>' . \esc_html__('Member', 'orchestra-projects') . '</th>';
        foreach ($pieceIds as $pieceId) {
            $head .= '<th>' . \esc_html($this->library->piece($pieceId)->title ?? '#' . $pieceId) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td><strong>' . \esc_html($row['name']) . '</strong></td>';
            foreach ($row['cells'] as $cell) {
                $body .= '<td>' . ($cell === null
                    ? '<span class="muted">—</span>'
                    : \esc_html($cell['part'] . ($cell['new'] ? '*' : ''))) . '</td>';
            }
            $body .= '</tr>';
        }

        return '<h2 class="by-member">' . \esc_html__('Parts by member', 'orchestra-projects') . '</h2>'
            . '<table class="matrix"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }
}
