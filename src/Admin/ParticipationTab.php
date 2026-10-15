<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\Participation;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\Project;
use Orchestra\Projects\Project\ProjectStatus;

final class ParticipationTab
{
    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
        private readonly Names $names,
    ) {
    }

    public function render(Project $project): void
    {
        $all = $this->participations->forProject($project->id);
        if ($all === []) {
            $message = $project->status === ProjectStatus::Draft
                ? \__('Members are asked to respond when the project is opened.', 'orchestra-projects')
                : \__('Nobody has been asked to respond.', 'orchestra-projects');
            echo '<p>' . \esc_html($message) . '</p>';
            return;
        }

        $assignmentCounts = [];
        foreach ($this->assignments->forProject($project->id) as $assignment) {
            $key = $assignment->userId ?? 0;
            $assignmentCounts[$key] = ($assignmentCounts[$key] ?? 0) + 1;
        }
        $canManage = \current_user_can(Capabilities::PARTICIPATION_MANAGE);

        foreach ([ParticipationStatus::Accepted, ParticipationStatus::Open, ParticipationStatus::Declined] as $status) {
            $group = \array_values(\array_filter($all, static fn (Participation $p): bool => $p->status === $status));
            \usort($group, fn (Participation $a, Participation $b): int => \strcasecmp(
                $this->names->of($a->userId),
                $this->names->of($b->userId)
            ));

            echo '<h2>' . \esc_html($status->label()) . ' (' . \count($group) . ')</h2>';
            if ($group === []) {
                echo '<p>—</p>';
                continue;
            }
            echo '<table class="wp-list-table widefat striped"><thead><tr>'
                . '<th scope="col">' . \esc_html__('Member', 'orchestra-projects') . '</th>'
                . '<th scope="col">' . \esc_html__('Responded', 'orchestra-projects') . '</th>'
                . '<th scope="col">' . \esc_html__('Assignments', 'orchestra-projects') . '</th>'
                . ($canManage ? '<th scope="col">' . \esc_html__('Change', 'orchestra-projects') . '</th>' : '')
                . '</tr></thead><tbody>';
            foreach ($group as $participation) {
                $count = $participation->userId === null ? 0 : ($assignmentCounts[$participation->userId] ?? 0);
                echo '<tr><td>' . \esc_html($this->names->of($participation->userId)) . '</td>'
                    . '<td>' . \esc_html(self::respondedAt($participation)) . '</td>'
                    . '<td>' . $count . '</td>';
                if ($canManage) {
                    echo '<td>' . $this->changeForm($project, $participation, $count) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    private function changeForm(Project $project, Participation $participation, int $assignmentCount): string
    {
        $fields = ['participation' => $participation->id];
        $html = '';
        if ($participation->status === ParticipationStatus::Accepted && $assignmentCount > 0) {
            $fields['confirm'] = 1;
            $message = \sprintf(
                /* translators: %d: number of assignments */
                \__('The member\'s %d assignments in this project will be removed. Continue?', 'orchestra-projects'),
                $assignmentCount
            );
            $html .= Form::open(
                $project->id,
                'participation',
                $fields,
                null,
                "return this.status.value === 'accepted' || confirm(" . Form::jsString($message) . ');'
            );
        } else {
            $html .= Form::open($project->id, 'participation', $fields);
        }

        $html .= '<select name="status">';
        foreach (ParticipationStatus::cases() as $status) {
            $selected = \selected($status->value, $participation->status->value, false);
            $html .= '<option value="' . $status->value . '"' . $selected . '>'
                . \esc_html($status->label()) . '</option>';
        }

        return $html . '</select> <button type="submit" class="button">' . \esc_html__('Change', 'orchestra-projects')
            . '</button></form>';
    }

    private static function respondedAt(Participation $participation): string
    {
        if ($participation->respondedAt === null) {
            return '—';
        }

        return \get_date_from_gmt($participation->respondedAt, ProjectsPage::dateFormat());
    }
}
