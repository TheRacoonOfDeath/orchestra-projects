<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Library\LibraryService;
use Orchestra\Library\Piece\Piece;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Program\ProgramService;
use Orchestra\Projects\Project\Project;

/**
 * The program of a project: read-only for members, editable for conductors and organizers.
 */
final class ProgramView
{
    public function __construct(
        private readonly ProgramRepository $program,
        private readonly ProgramService $programService,
        private readonly LibraryService $library,
    ) {
    }

    /**
     * The ordered program for everyone.
     */
    public function list(Project $project): string
    {
        $items = '';
        foreach ($this->program->pieceIds($project->id) as $pieceId) {
            $piece = $this->library->piece($pieceId);
            if ($piece !== null) {
                $items .= '<li><strong>' . \esc_html($piece->title) . '</strong>'
                    . ($piece->composer === '' ? '' : '<span class="orchestra-muted">' . \esc_html($piece->composer)
                    . '</span>')
                    . '</li>';
            }
        }

        return $items === ''
            ? '<p class="orchestra-muted">'
                . \esc_html__('The program has not been published yet.', 'orchestra-projects') . '</p>'
            : '<ol class="orchestra-program">' . $items . '</ol>';
    }

    public function manage(Project $project): string
    {
        $pieceIds = $this->program->pieceIds($project->id);
        $editable = $project->status->programEditable() && \current_user_can(Capabilities::PROGRAM_MANAGE);
        $html = '';
        if (!$project->status->programEditable()) {
            $html .= '<div class="orchestra-notice"><p>'
                . \esc_html__('The program of a completed project cannot be changed.', 'orchestra-projects')
                . '</p></div>';
        }

        $rows = [];
        $last = \count($pieceIds) - 1;
        foreach ($pieceIds as $index => $pieceId) {
            $piece = $this->library->piece($pieceId);
            $assignments = $this->programService->assignmentCount($project->id, $pieceId);
            $row = [
                (string) ($index + 1),
                '<strong>' . \esc_html($piece->title ?? '#' . $pieceId) . '</strong>'
                    . ($piece?->archived === true ? ' '
                    . Html::badge(\__('archived', 'orchestra-projects'), 'warning') : ''),
                \esc_html($piece->composer ?? ''),
                (string) \count($this->library->parts($pieceId)),
                (string) $assignments,
            ];
            if ($editable) {
                $actions = '';
                if ($index > 0) {
                    $actions .= ProjectForms::button($project->id, 'program_up', '↑', ['piece' => $pieceId]);
                }
                if ($index < $last) {
                    $actions .= ProjectForms::button($project->id, 'program_down', '↓', ['piece' => $pieceId]);
                }
                $confirm = $assignments === 0
                    ? \__('Remove the piece from the program?', 'orchestra-projects')
                    : \sprintf(
                        /* translators: %d: number of assignments */
                        \__('Remove the piece? Its %d assignments will be removed.', 'orchestra-projects'),
                        $assignments
                    );
                $actions .= ProjectForms::button(
                    $project->id,
                    'program_remove',
                    \__('Remove', 'orchestra-projects'),
                    ['piece' => $pieceId, 'confirm' => 1],
                    $confirm,
                    'small danger'
                );
                $row[] = '<div class="orchestra-actions">' . $actions . '</div>';
            }
            $rows[] = $row;
        }

        $headings = [
            '#',
            \__('Piece', 'orchestra-projects'),
            \__('Composer', 'orchestra-projects'),
            \__('Parts', 'orchestra-projects'),
            \__('Assignments', 'orchestra-projects'),
        ];
        if ($editable) {
            $headings[] = '';
        }
        $html .= Html::table($headings, $rows, \__('The program is empty.', 'orchestra-projects'));

        return $editable ? $html . $this->addForm($project, $pieceIds) : $html;
    }

    /**
     * @param list<int> $pieceIds
     */
    private function addForm(Project $project, array $pieceIds): string
    {
        $available = \array_values(\array_filter(
            $this->library->searchPieces('', false, 1000),
            static fn (Piece $piece): bool => !\in_array($piece->id, $pieceIds, true)
        ));
        if ($available === []) {
            return '<p class="orchestra-muted">'
                . \esc_html__('All pieces of the library are in the program.', 'orchestra-projects') . '</p>';
        }

        $options = '';
        foreach ($available as $piece) {
            $label = $piece->composer === '' ? $piece->title : $piece->title . ' (' . $piece->composer . ')';
            $options .= '<option value="' . $piece->id . '">' . \esc_html($label) . '</option>';
        }

        return '<div class="orchestra-card">'
            . ProjectForms::open($project->id, 'program_add', [], ['class' => 'orchestra-form'])
            . Html::field(
                'orchestra-projects-piece',
                \__('Add a piece', 'orchestra-projects'),
                '<select id="orchestra-projects-piece" name="piece">' . $options . '</select>'
            )
            . '<div class="orchestra-actions">' . Form::submit(\__('Add to program', 'orchestra-projects'), 'primary')
            . '</div></form></div>';
    }
}
