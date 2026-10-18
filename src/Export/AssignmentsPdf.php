<?php

declare(strict_types=1);

namespace Orchestra\Projects\Export;

use Orchestra\Core\Pdf\Pdf;
use Orchestra\Library\LibraryService;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Ui\Dates;

/**
 * The assignments of a project as a PDF: every piece of the program with its parts and players.
 */
final class AssignmentsPdf
{
    public function __construct(
        private readonly ProgramRepository $program,
        private readonly AssignmentRepository $assignments,
        private readonly LibraryService $library,
        private readonly Names $names,
    ) {
    }

    public function html(Project $project): string
    {
        $players = [];
        foreach ($this->assignments->forProject($project->id) as $assignment) {
            $players[$assignment->partId][] = $this->names->of($assignment->userId);
        }

        $facts = [Dates::range($project)];
        if ($project->concertDates !== []) {
            $facts[] = Dates::concertsLabel($project) . ': ' . Dates::concerts($project);
        }
        $body = '<p class="muted">' . \esc_html(\implode(' · ', $facts)) . '</p>';

        $pieceIds = $this->program->pieceIds($project->id);
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

        return Pdf::document(
            \sprintf(
                /* translators: %s: project name */
                \__('Assignments: %s', 'orchestra-projects'),
                $project->title
            ),
            $body,
            '.part { width: 32%; }'
        );
    }
}
