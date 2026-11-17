<?php

declare(strict_types=1);

namespace Orchestra\Projects\Frontend;

use Orchestra\Core\Ui\LoginPageRedirect;
use Orchestra\Core\Ui\Notices;
use Orchestra\Core\Ui\Redirect;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Field\ProjectAnswers;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Project\ProjectRepository;

/**
 * Stores a member's answers to the fields of a project (admin-post.php?action=orchestra_projects_answer).
 * Only the member answers, for their own participation.
 */
final class AnswersController
{
    public const ACTION = 'orchestra_projects_answer';

    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly ProjectRepository $projects,
        private readonly ProjectAnswers $answers,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        \add_action('admin_post_nopriv_' . self::ACTION, [LoginPageRedirect::class, 'requireLogin']);
    }

    public static function nonceAction(int $participationId): string
    {
        return self::ACTION . '_' . $participationId;
    }

    /**
     * @internal Hooked to `admin_post_orchestra_projects_answer`.
     */
    public function handle(): never
    {
        $participationId = \absint(\is_string($_POST['participation'] ?? null) ? $_POST['participation'] : 0);
        \check_admin_referer(self::nonceAction($participationId));

        $participation = $this->participations->find($participationId);
        $project = $participation === null ? null : $this->projects->find($participation->projectId);
        if (
            $participation === null
            || $project === null
            || $participation->userId !== \get_current_user_id()
            || !\current_user_can(Capabilities::PARTICIPATION_RESPOND, $participationId)
        ) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-projects'), 403);
        }

        /** @var array<mixed> $input */
        $input = \wp_unslash($_POST);
        $errors = $this->answers->save($project, $participation, $input);
        $errors === []
            ? Notices::success(\__('Your answers have been saved.', 'orchestra-projects'))
            : Notices::errors($errors);
        Redirect::back();
    }
}
