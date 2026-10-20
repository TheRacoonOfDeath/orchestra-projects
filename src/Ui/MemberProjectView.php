<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Library\LibraryService;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Frontend\ResponseController;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationRules;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Program\ProgramRepository;
use Orchestra\Projects\Project\Project;

/**
 * What a project means for the current user: their response and their parts with the sheet music.
 */
final class MemberProjectView
{
    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
        private readonly ProgramRepository $program,
        private readonly LibraryService $library,
        private readonly AssignmentService $assignmentService,
    ) {
    }

    public function participation(Project $project, bool $explain = true): string
    {
        $participation = $this->participations->findFor($project->id, \get_current_user_id());
        if ($participation === null || !\current_user_can(Capabilities::PARTICIPATION_READ, $participation->id)) {
            return '';
        }

        $html = '<div class="orchestra-actions"><span>' . \esc_html__('Your participation:', 'orchestra-projects')
            . '</span> ' . Badges::participation($participation->status) . '</div>';
        if ($this->canRespond($project, $participation)) {
            $html .= '<div class="orchestra-actions">'
                . self::responseButton($participation, ParticipationStatus::Accepted)
                . self::responseButton($participation, ParticipationStatus::Declined) . '</div>';
            if ($explain) {
                $html .= '<p class="orchestra-muted">' . \esc_html__(
                    'Your decision is final. To change it later, contact an organizer.',
                    'orchestra-projects'
                ) . '</p>';
            }
        }

        return $html;
    }

    public function parts(Project $project): string
    {
        if (!\current_user_can(Capabilities::ASSIGNMENT_READ)) {
            return '';
        }
        $assignments = $this->assignments->forProjectAndUser($project->id, \get_current_user_id());
        if ($assignments === []) {
            return '';
        }

        $order = \array_flip($this->program->pieceIds($project->id));
        \usort($assignments, static fn ($a, $b): int => ($order[$a->pieceId] ?? 0) <=> ($order[$b->pieceId] ?? 0));

        $new = $this->assignmentService->newParts(
            $project->id,
            \array_map(static fn ($a): int => $a->pieceId, $assignments)
        );
        $items = '';
        $partIds = [];
        foreach ($assignments as $assignment) {
            $part = $this->library->part($assignment->partId);
            $piece = $this->library->piece($assignment->pieceId);
            if ($part === null || $piece === null) {
                continue;
            }
            $file = $this->library->sheetMusic($part->id);
            if ($file !== null) {
                $partIds[] = $part->id;
            }
            // The piece with its composer on the first line, the part with its download on the second.
            $items .= '<li><div class="orchestra-cell"><div><strong>' . \esc_html($piece->title) . '</strong>'
                . ($piece->composer === ''
                    ? ''
                    : ' <span class="orchestra-muted">(' . \esc_html($piece->composer) . ')</span>')
                . '</div><div class="orchestra-actions"><span>' . \esc_html($part->name) . '</span>'
                . (isset($new[$assignment->id]) ? Badges::newPart() : '')
                . ($file === null
                    ? '<span class="orchestra-muted">' . \esc_html__('No sheet music yet', 'orchestra-projects')
                        . '</span>'
                    : Form::link(
                        $this->library->downloadUrl($file),
                        \__('Download', 'orchestra-projects'),
                        'small'
                    ))
                . '</div></div></li>';
        }
        if ($items === '') {
            return '';
        }

        $html = '<h4>' . \esc_html__('Your parts', 'orchestra-projects') . '</h4><ul class="orchestra-list">' . $items
            . '</ul>';
        if (\count($partIds) > 1) {
            $html .= '<div class="orchestra-actions">' . Form::link(
                $this->library->bundleDownloadUrl($partIds, $project->title),
                \__('Download all as a zip file', 'orchestra-projects'),
                'primary'
            ) . '</div>';
        }

        return $html;
    }

    private function canRespond(Project $project, Participation $participation): bool
    {
        return \current_user_can(Capabilities::PARTICIPATION_RESPOND, $participation->id)
            && ParticipationRules::responseRefusal(
                $project->status,
                $participation->status,
                ParticipationStatus::Accepted
            ) === null;
    }

    private static function responseButton(Participation $participation, ParticipationStatus $response): string
    {
        $accept = $response === ParticipationStatus::Accepted;

        return Form::button(
            ResponseController::ACTION,
            ResponseController::nonceAction($participation->id),
            $accept ? \__('Accept', 'orchestra-projects') : \__('Decline', 'orchestra-projects'),
            ['participation' => $participation->id, 'response' => $response->value],
            $accept
                ? \__('Accept to participate? Your decision is final.', 'orchestra-projects')
                : \__('Decline to participate? Your decision is final.', 'orchestra-projects'),
            $accept ? 'primary' : ''
        );
    }
}
