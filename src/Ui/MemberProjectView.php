<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Library\LibraryService;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Field\ProjectAnswers;
use Orchestra\Projects\Field\ProjectField;
use Orchestra\Projects\Frontend\AnswersController;
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
        private readonly ProjectAnswers $answers,
    ) {
    }

    /**
     * Whether the current user still has to respond to the project: it is open for participation
     * and their participation is pending. Only then is the registration deadline shown in red.
     */
    public function awaitsResponse(Project $project): bool
    {
        if (!$project->showsDeadline()) {
            return false;
        }
        $participation = $this->participations->findFor($project->id, \get_current_user_id());

        return $participation !== null && $participation->status === ParticipationStatus::Open;
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
            // The project page has room for a comment; the cards of the project list do not.
            return $html . ($explain
                ? self::responseForm($participation) . '<p class="orchestra-muted">' . \esc_html__(
                    'Your decision is final. To change it later, contact an organizer.',
                    'orchestra-projects'
                ) . '</p>'
                : '<div class="orchestra-actions">'
                    . self::responseButton($participation, ParticipationStatus::Accepted)
                    . self::responseButton($participation, ParticipationStatus::Declined) . '</div>');
        }
        if ($explain && $participation->userId === \get_current_user_id()) {
            $html .= self::commentForm($project, $participation);
        }

        return $html;
    }

    /**
     * Accept or decline, with an optional comment for the organizers.
     */
    private static function responseForm(Participation $participation): string
    {
        $id = 'orchestra-projects-comment-' . $participation->id;

        return Form::open(
            ResponseController::ACTION,
            ResponseController::nonceAction($participation->id),
            ['participation' => $participation->id],
            ['class' => 'orchestra-stack']
        ) . self::commentField($id, $participation->memberComment)
            . '<div class="orchestra-actions">'
            . self::responseSubmit(ParticipationStatus::Accepted)
            . self::responseSubmit(ParticipationStatus::Declined) . '</div></form>';
    }

    /**
     * The member's comment after responding: shown, and changeable while the project is open or in
     * preparation.
     */
    private static function commentForm(Project $project, Participation $participation): string
    {
        $comment = $participation->memberComment;
        $shown = $comment === null ? '' : '<p><span class="orchestra-muted">'
            . \esc_html__('Your comment:', 'orchestra-projects') . '</span> '
            . \nl2br(\esc_html($comment)) . '</p>';
        $open = $participation->status === ParticipationStatus::Open;
        if ($open || !ParticipationRules::commentsOpen($project->status)) {
            return $shown;
        }

        return $shown . '<details class="orchestra-disclosure"><summary>' . \esc_html($comment === null
            ? \__('Add a comment for the organizers', 'orchestra-projects')
            : \__('Change your comment', 'orchestra-projects')) . '</summary>'
            . Form::open(
                ResponseController::ACTION,
                ResponseController::nonceAction($participation->id),
                ['participation' => $participation->id, 'response' => ResponseController::ONLY_COMMENT],
                ['class' => 'orchestra-stack']
            ) . self::commentField('orchestra-projects-comment-' . $participation->id, $comment)
            . '<div class="orchestra-actions">' . Form::submit(\__('Save comment', 'orchestra-projects'), 'small')
            . '</div></form></details>';
    }

    private static function commentField(string $id, ?string $comment): string
    {
        return Html::field(
            $id,
            \__('Comment for the organizers (optional)', 'orchestra-projects'),
            '<textarea id="' . \esc_attr($id) . '" name="' . ResponseController::FIELD_COMMENT . '" rows="2"'
                . ' maxlength="' . ParticipationRules::COMMENT_MAX_LENGTH . '">' . \esc_textarea($comment ?? '')
                . '</textarea>',
            \__('E.g. if you can only come to some of the concerts. Only the organizers see it.', 'orchestra-projects')
        );
    }

    private static function responseSubmit(ParticipationStatus $response): string
    {
        $accept = $response === ParticipationStatus::Accepted;

        $confirm = $accept
            ? \__('Accept to participate? Your decision is final.', 'orchestra-projects')
            : \__('Decline to participate? Your decision is final.', 'orchestra-projects');

        return '<button type="submit" class="orchestra-button' . ($accept ? ' orchestra-button--primary' : '') . '"'
            . ' name="response" value="' . \esc_attr($response->value) . '"'
            . ' data-orchestra-confirm="' . \esc_attr($confirm) . '">'
            . \esc_html($accept ? \__('Accept', 'orchestra-projects') : \__('Decline', 'orchestra-projects'))
            . '</button>';
    }

    /**
     * "Questions": the fields of the project for a member who accepted, answered while the project is
     * open for participation and shown afterwards. Required fields not answered yet are marked. The
     * cards of the project list show it collapsed, above "Your parts".
     */
    public function answers(Project $project, bool $collapsed = false): string
    {
        $fields = $this->answers->fields($project);
        $userId = \get_current_user_id();
        $participation = $fields === [] ? null : $this->participations->findFor($project->id, $userId);
        if (
            $participation === null
            || $participation->status !== ParticipationStatus::Accepted
            || !\current_user_can(Capabilities::PARTICIPATION_READ, $participation->id)
        ) {
            return '';
        }

        $answers = $this->answers->of($project, $userId);
        $open = ProjectAnswers::open($project, $participation);
        $missing = $open ? $this->answers->missing($project, $userId) : [];
        if ($open) {
            $content = Form::open(
                AnswersController::ACTION,
                AnswersController::nonceAction($participation->id),
                ['participation' => $participation->id],
                ['class' => 'orchestra-stack']
            );
            foreach ($fields as $field) {
                $id = 'orchestra-projects-answer-' . $project->id . '-' . $field->id;
                $content .= Html::field(
                    $id,
                    $field->label . ($field->required ? ' *' : ''),
                    FieldControl::control($field, $answers[$field->id] ?? '', $id),
                    FieldControl::hint($field)
                );
            }
            $content .= ($this->hasRequired($fields)
                ? '<p class="orchestra-muted">' . \esc_html__('* Please answer.', 'orchestra-projects') . '</p>'
                : '')
                . '<div class="orchestra-actions">' . Form::submit(\__('Save answers', 'orchestra-projects'), 'small')
                . '</div></form>';
        } else {
            $content = Html::details(\array_map(
                static fn (ProjectField $field): array => [
                    $field->label,
                    FieldControl::value($field, $answers[$field->id] ?? ''),
                ],
                $fields
            ));
        }

        Assets::enqueue();
        $title = '<h4 class="orchestra-projects-section-title">' . \esc_html__('Questions', 'orchestra-projects')
            . '</h4>'
            . ($missing === [] ? '' : ' ' . Html::badge(\__('Please answer', 'orchestra-projects'), 'warning'));

        return $collapsed
            ? '<details class="orchestra-projects-parts"><summary>' . $title . '</summary>' . $content . '</details>'
            : '<div class="orchestra-stack"><div class="orchestra-actions">' . $title . '</div>' . $content . '</div>';
    }

    /**
     * @param list<ProjectField> $fields
     */
    private function hasRequired(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->required) {
                return true;
            }
        }

        return false;
    }

    /**
     * The member's part in every piece of the program, with the sheet music; pieces in which they
     * have no part yet say so. Shown to members who take part or have parts. Until the parts are
     * assigned, members only learn that ({@see AssignmentsView::hiddenFromMembers()}). The cards of
     * the project list show them collapsed, so the cards stay short.
     */
    public function parts(Project $project, bool $collapsed = false): string
    {
        if (!\current_user_can(Capabilities::ASSIGNMENT_READ)) {
            return '';
        }
        $userId = \get_current_user_id();
        $assignments = $this->assignments->forProjectAndUser($project->id, $userId);
        $participation = $this->participations->findFor($project->id, $userId);
        $pieceIds = $this->program->pieceIds($project->id);
        if ($pieceIds === [] || ($assignments === [] && $participation?->status !== ParticipationStatus::Accepted)) {
            return '';
        }

        if (AssignmentsView::hiddenFromMembers($project)) {
            return self::partsSection('<p class="orchestra-muted">'
                . \esc_html(AssignmentsView::notAssignedYet()) . '</p>', $collapsed);
        }

        $byPiece = [];
        foreach ($assignments as $assignment) {
            $byPiece[$assignment->pieceId] = $assignment;
        }
        $new = $this->assignmentService->newParts($project->id, \array_keys($byPiece));
        $noPart = $project->status->assignmentsEditable()
            ? \__('No part assigned yet', 'orchestra-projects')
            : \__('No part', 'orchestra-projects');
        $items = '';
        $partIds = [];
        foreach ($pieceIds as $pieceId) {
            $piece = $this->library->piece($pieceId);
            if ($piece === null) {
                continue;
            }
            $assignment = $byPiece[$pieceId] ?? null;
            $part = $assignment === null ? null : $this->library->part($assignment->partId);
            $file = $part === null ? null : $this->library->sheetMusic($part->id);
            if ($part !== null && $file !== null) {
                $partIds[] = $part->id;
            }
            // The piece with its composer on the first line, the part with its download on the second.
            $items .= '<li><div class="orchestra-cell"><div><strong>' . \esc_html($piece->title) . '</strong>'
                . ($piece->composer === ''
                    ? ''
                    : ' <span class="orchestra-muted">(' . \esc_html($piece->composer) . ')</span>')
                . '</div><div class="orchestra-actions">'
                . ($part === null
                    ? '<span class="orchestra-muted">' . \esc_html($noPart) . '</span>'
                    : '<span>' . \esc_html($part->name) . '</span>'
                        . (isset($new[$assignment->id]) ? Badges::newPart() : '')
                        . ($file === null
                            ? '<span class="orchestra-muted">'
                                . \esc_html__('No sheet music yet', 'orchestra-projects') . '</span>'
                            : Form::link(
                                $this->library->downloadUrl($file),
                                \__('Download', 'orchestra-projects'),
                                'small'
                            )))
                . '</div></div></li>';
        }
        if ($items === '') {
            return '';
        }

        $html = '<ul class="orchestra-list">' . $items . '</ul>';
        if (\count($partIds) > 1) {
            $html .= '<div class="orchestra-actions">' . Form::link(
                $this->library->bundleDownloadUrl($partIds, $project->title),
                \__('Download all as a zip file', 'orchestra-projects'),
                'primary'
            ) . '</div>';
        }

        return self::partsSection($html, $collapsed);
    }

    /**
     * "Your parts" with a section label, smaller than the name of the project; collapsed, the label
     * opens the content.
     */
    private static function partsSection(string $content, bool $collapsed): string
    {
        $title = '<h4 class="orchestra-projects-section-title">' . \esc_html__('Your parts', 'orchestra-projects')
            . '</h4>';

        return $collapsed
            ? '<details class="orchestra-projects-parts"><summary>' . $title . '</summary>' . $content . '</details>'
            : $title . $content;
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
