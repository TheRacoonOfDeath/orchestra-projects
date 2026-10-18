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
     * The names of everyone who accepted, for the project overview; "" for users who may not see
     * them or when nobody has accepted yet.
     */
    public function accepted(Project $project): string
    {
        if (!\current_user_can(Capabilities::PARTICIPANTS_READ)) {
            return '';
        }

        $names = [];
        foreach ($this->participations->forProject($project->id, ParticipationStatus::Accepted) as $participation) {
            if ($participation->userId !== null) {
                $names[] = $this->names->of($participation->userId);
            }
        }
        if ($names === []) {
            return '';
        }
        \natcasesort($names);

        return '<section class="orchestra-stack"><h3>' . \esc_html__('Accepted', 'orchestra-projects')
            . ' <span class="orchestra-muted">(' . \count($names) . ')</span></h3><div class="orchestra-checklist">'
            . \implode('', \array_map(static fn (string $n): string => '<span>' . \esc_html($n) . '</span>', $names))
            . '</div></section>';
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
                \__('Responded', 'orchestra-projects'),
                \__('Assignments', 'orchestra-projects'),
            ];
            if ($canManage) {
                $headings[] = \__('Change', 'orchestra-projects');
            }
            $rows = [];
            foreach ($group as $participation) {
                $count = $participation->userId === null ? 0 : ($assignmentCounts[$participation->userId] ?? 0);
                $row = [
                    \esc_html($this->names->of($participation->userId)),
                    \esc_html($participation->respondedAt === null ? '—' : Dates::fromGmt($participation->respondedAt)),
                    (string) $count,
                ];
                if ($canManage) {
                    $row[] = $this->changeForm($project, $participation, $count);
                }
                $rows[] = $row;
            }
            $html .= Html::table($headings, $rows);
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
}
