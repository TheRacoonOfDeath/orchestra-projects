<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Library\LibraryService;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Program\ProgramService;
use Orchestra\Projects\Project\Project;

final class ProgramTab
{
    public function __construct(
        private readonly ProgramRepository $program,
        private readonly ProgramService $programService,
        private readonly LibraryService $library,
    ) {
    }

    public function render(Project $project): void
    {
        $pieceIds = $this->program->pieceIds($project->id);
        $editable = $project->status->programEditable();
        if (!$editable) {
            echo '<p>'
                . \esc_html__('The program of a completed or archived project cannot be changed.', 'orchestra-projects')
                . '</p>';
        }

        echo '<table class="wp-list-table widefat striped"><thead><tr>'
            . '<th scope="col" style="width:3em">#</th>'
            . '<th scope="col">' . \esc_html__('Piece', 'orchestra-projects') . '</th>'
            . '<th scope="col">' . \esc_html__('Composer', 'orchestra-projects') . '</th>'
            . '<th scope="col">' . \esc_html__('Parts', 'orchestra-projects') . '</th>'
            . '<th scope="col">' . \esc_html__('Assignments', 'orchestra-projects') . '</th>'
            . ($editable ? '<th scope="col"></th>' : '')
            . '</tr></thead><tbody>';
        if ($pieceIds === []) {
            echo '<tr><td colspan="6">' . \esc_html__('The program is empty.', 'orchestra-projects') . '</td></tr>';
        }

        $last = \count($pieceIds) - 1;
        foreach ($pieceIds as $index => $pieceId) {
            $piece = $this->library->piece($pieceId);
            $assignments = $this->programService->assignmentCount($project->id, $pieceId);
            echo '<tr><td>' . ($index + 1) . '</td>'
                . '<td>' . \esc_html($piece->title ?? '#' . $pieceId)
                . ($piece?->archived === true
                    ? ' <em>(' . \esc_html__('archived', 'orchestra-projects') . ')</em>'
                    : '')
                . '</td><td>' . \esc_html($piece->composer ?? '') . '</td>'
                . '<td>' . \count($this->library->parts($pieceId)) . '</td><td>' . $assignments . '</td>';
            if ($editable) {
                echo '<td>';
                if ($index > 0) {
                    echo Form::button($project->id, 'program_up', '↑', ['piece' => $pieceId]) . ' ';
                }
                if ($index < $last) {
                    echo Form::button($project->id, 'program_down', '↓', ['piece' => $pieceId]) . ' ';
                }
                $confirm = $assignments === 0
                    ? \__('Remove the piece from the program?', 'orchestra-projects')
                    : \sprintf(
                        /* translators: %d: number of assignments */
                        \__('Remove the piece? Its %d assignments will be removed.', 'orchestra-projects'),
                        $assignments
                    );
                echo Form::button(
                    $project->id,
                    'program_remove',
                    \__('Remove', 'orchestra-projects'),
                    ['piece' => $pieceId, 'confirm' => 1],
                    $confirm,
                    'button-link button-link-delete'
                );
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($editable) {
            $this->renderAddForm($project, $pieceIds);
        }
    }

    /**
     * @param list<int> $pieceIds
     */
    private function renderAddForm(Project $project, array $pieceIds): void
    {
        $available = \array_values(\array_filter(
            $this->library->searchPieces('', false, 1000),
            static fn ($piece): bool => !\in_array($piece->id, $pieceIds, true)
        ));

        echo '<h2>' . \esc_html__('Add a piece', 'orchestra-projects') . '</h2>';
        if ($available === []) {
            echo '<p>' . \esc_html__('All pieces of the library are in the program.', 'orchestra-projects') . '</p>';
            return;
        }

        echo Form::open($project->id, 'program_add');
        echo '<label class="screen-reader-text" for="orchestra-projects-piece">'
            . \esc_html__('Piece', 'orchestra-projects') . '</label>'
            . '<select id="orchestra-projects-piece" name="piece">';
        foreach ($available as $piece) {
            $label = $piece->composer === '' ? $piece->title : $piece->title . ' (' . $piece->composer . ')';
            echo '<option value="' . $piece->id . '">' . \esc_html($label) . '</option>';
        }
        echo '</select> <button type="submit" class="button button-primary">' . \esc_html__('Add', 'orchestra-projects')
            . '</button></form>';
    }
}
