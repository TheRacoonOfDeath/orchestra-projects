<?php

declare(strict_types=1);

namespace Orchestra\Projects\Participation;

use Orchestra\Projects\Project\ProjectStatus;

/**
 * When members may respond to a project themselves, and how comments and notes are stored.
 */
final class ParticipationRules
{
    public const COMMENT_MAX_LENGTH = 500;
    public const NOTE_MAX_LENGTH = 1000;

    /**
     * Members comment on their participation (e.g. "only from the 27th") while the project is open
     * or in preparation, whatever they answered.
     */
    public static function commentsOpen(ProjectStatus $projectStatus): bool
    {
        return \in_array($projectStatus, [ProjectStatus::Open, ProjectStatus::Preparation], true);
    }

    /**
     * A comment or note as stored: plain text with line breaks, shortened to the maximum length,
     * null if empty.
     */
    public static function text(string $input, int $maxLength): ?string
    {
        $text = \trim(\str_replace(["\r\n", "\r"], "\n", \strip_tags($input)));
        $text = (string) \preg_replace("/\n{3,}/", "\n\n", $text);

        return $text === '' ? null : \mb_substr($text, 0, $maxLength);
    }

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
