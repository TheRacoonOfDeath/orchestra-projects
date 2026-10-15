<?php

declare(strict_types=1);

namespace Orchestra\Projects\Participation;

use Orchestra\Projects\Project\ProjectStatus;

/**
 * When members may respond to a project themselves.
 */
final class ParticipationRules
{
    /**
     * @return string|null Why the response is not allowed, or null if it is
     */
    public static function responseRefusal(
        ProjectStatus $projectStatus,
        ParticipationStatus $current,
        ParticipationStatus $response,
    ): ?string {
        if ($response === ParticipationStatus::Open) {
            return \__('Please accept or decline.', 'orchestra-projects');
        }
        if (!$projectStatus->membersCanRespond()) {
            return \__('This project is not open for responses.', 'orchestra-projects');
        }
        if ($current !== ParticipationStatus::Open) {
            return \__(
                'You have already responded. Please contact an organizer to change your decision.',
                'orchestra-projects'
            );
        }

        return null;
    }
}
