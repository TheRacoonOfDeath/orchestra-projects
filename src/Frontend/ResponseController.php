<?php

declare(strict_types=1);

namespace Orchestra\Projects\Frontend;

use Orchestra\Projects\Admin\Notices;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Participation\ParticipationService;
use Orchestra\Projects\Participation\ParticipationStatus;

/**
 * Stores a member's response (admin-post.php?action=orchestra_projects_respond).
 */
final class ResponseController
{
    public const ACTION = 'orchestra_projects_respond';

    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly ParticipationService $participation,
        private readonly Notices $notices,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        \add_action('admin_post_nopriv_' . self::ACTION, 'auth_redirect');
    }

    public static function nonceAction(int $participationId): string
    {
        return self::ACTION . '_' . $participationId;
    }

    /**
     * @internal Hooked to `admin_post_orchestra_projects_respond`.
     */
    public function handle(): never
    {
        $participationId = \absint(\is_string($_POST['participation'] ?? null) ? $_POST['participation'] : 0);
        \check_admin_referer(self::nonceAction($participationId));

        $participation = $this->participations->find($participationId);
        $response = ParticipationStatus::tryFrom(\is_string($_POST['response'] ?? null) ? $_POST['response'] : '');
        if (
            $participation === null
            || $response === null
            || !\current_user_can(Capabilities::PARTICIPATION_RESPOND, $participationId)
        ) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-projects'), 403);
        }

        $error = $this->participation->respond($participation, \get_current_user_id(), $response);
        if ($error === null) {
            $this->notices->success($response === ParticipationStatus::Accepted
                ? \__('Thank you! You have accepted to participate.', 'orchestra-projects')
                : \__('Thank you! You have declined to participate.', 'orchestra-projects'));
        } else {
            $this->notices->error($error);
        }

        \wp_safe_redirect((string) (\wp_get_referer() ?: \home_url('/')));
        exit;
    }
}
