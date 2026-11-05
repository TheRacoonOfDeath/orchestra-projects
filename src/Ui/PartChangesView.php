<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Form;
use Orchestra\Core\Ui\Html;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Members\MembersService;
use Orchestra\Projects\Admin\Names;
use Orchestra\Projects\Assignment\AssignmentChanges;
use Orchestra\Projects\Assignment\AssignmentChangeService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Email\ChangedPartsEmail;
use Orchestra\Projects\Project\Project;

/**
 * On the assignments tab of a project in Parts assigned: the parts changed since the members were
 * last informed, with "Inform members about changes" (a review of the changes, then sending) and
 * "Don't inform members".
 */
final class PartChangesView
{
    /** `&orchestra_changes=review` shows the review before informing the members. */
    public const PARAMETER = 'orchestra_changes';
    public const REVIEW = 'review';

    public function __construct(
        private readonly AssignmentChangeService $changes,
        private readonly ChangedPartsEmail $email,
        private readonly Names $names,
        private readonly MembersService $members,
    ) {
    }

    /**
     * The notice with the buttons, the review when it was asked for, or nothing.
     *
     * @param bool|null $review whether to show the review; null to read it from the URL
     */
    public function render(Project $project, ?bool $review = null): string
    {
        if (!\current_user_can(Capabilities::ASSIGNMENT_MANAGE)) {
            return '';
        }
        $changes = $this->changes->pending($project);
        if ($changes === []) {
            return '';
        }

        $review ??= ($_GET[self::PARAMETER] ?? null) === self::REVIEW;

        return $review ? $this->review($project, $changes) : $this->notice($project, $changes);
    }

    /**
     * How many members have changes nobody informed them about.
     */
    public function affectedCount(Project $project): int
    {
        return \count(AssignmentChanges::byUser($this->changes->pending($project)));
    }

    /**
     * @param non-empty-list<\Orchestra\Projects\Assignment\PartChange> $changes
     */
    private function notice(Project $project, array $changes): string
    {
        $count = \count(AssignmentChanges::byUser($changes));

        return '<div class="orchestra-notice orchestra-notice--warning"><p>' . \esc_html(\sprintf(
            /* translators: %d: number of members */
            \_n(
                'The parts of %d member changed since the members were last informed.',
                'The parts of %d members changed since the members were last informed.',
                $count,
                'orchestra-projects'
            ),
            $count
        )) . '</p><div class="orchestra-actions">'
            . Form::link(
                \add_query_arg(self::PARAMETER, self::REVIEW, Redirect::currentUrl()),
                \__('Inform members about changes', 'orchestra-projects'),
                'primary small'
            )
            . ProjectForms::button(
                $project->id,
                'changes_forget',
                \__("Don't inform members", 'orchestra-projects'),
                [],
                \__(
                    'The changed parts stay as they are, but the members are not told about them, and the changes'
                        . ' are no longer listed here. Continue?',
                    'orchestra-projects'
                )
            )
            . '</div></div>';
    }

    /**
     * @param non-empty-list<\Orchestra\Projects\Assignment\PartChange> $changes
     */
    private function review(Project $project, array $changes): string
    {
        $byUser = AssignmentChanges::byUser($changes);
        $receiving = \array_flip($this->email->recipients(\array_keys($byUser)));

        $rows = [];
        foreach ($byUser as $userId => $own) {
            $rows[] = [
                \esc_html($this->names->of($userId)),
                $this->email->changesHtml($project, $own),
                isset($receiving[$userId])
                    ? Html::badge(\__('Email', 'orchestra-projects'), 'success')
                    : Html::badge($this->noEmailReason($userId), 'warning'),
            ];
        }
        $count = \count($receiving);
        $back = \remove_query_arg(self::PARAMETER, Redirect::currentUrl());

        return '<section class="orchestra-card orchestra-stack"><h3 class="orchestra-card__title">'
            . \esc_html__('Inform members about changes', 'orchestra-projects') . '</h3><p>'
            . \esc_html__(
                'Every member below gets an email with only their own changes. The text is set on the Email page'
                    . ' under "Automatic emails".',
                'orchestra-projects'
            ) . '</p>'
            . Html::table(
                [
                    \__('Member', 'orchestra-projects'),
                    \__('Changes', 'orchestra-projects'),
                    \__('Email', 'orchestra-projects'),
                ],
                $rows
            )
            . '<div class="orchestra-actions">'
            . ProjectForms::open(
                $project->id,
                'changes_inform',
                ['fingerprint' => AssignmentChanges::fingerprint($changes), Redirect::FIELD => $back],
                [
                    'class' => 'orchestra-inline-form',
                    'data-orchestra-confirm' => \sprintf(
                        /* translators: %d: number of members */
                        \_n(
                            'Send the email to %d member now?',
                            'Send the email to %d members now?',
                            $count,
                            'orchestra-projects'
                        ),
                        $count
                    ),
                ]
            )
            . Form::submit(\__('Send', 'orchestra-projects'), 'primary') . '</form>'
            . Form::link($back, \__('Back', 'orchestra-projects'))
            . '</div></section>';
    }

    private function noEmailReason(int $userId): string
    {
        $user = \get_userdata($userId);
        if ($user === false || !$this->members->isActive($userId)) {
            return \__('No email: inactive', 'orchestra-projects');
        }
        if (\is_email($user->user_email) === false) {
            return \__('No email: no address', 'orchestra-projects');
        }

        return \__('No email: turned off in the profile', 'orchestra-projects');
    }
}
