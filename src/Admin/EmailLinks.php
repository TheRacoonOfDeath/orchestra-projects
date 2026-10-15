<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Core\Email\EmailService;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Project\Project;

/**
 * Buttons that open the email screen of orchestra-core with a project's groups selected.
 * Shows nothing without the email service (orchestra-core before 0.3).
 */
final class EmailLinks
{
    public function __construct(private readonly ?EmailService $email)
    {
    }

    /**
     * @param array<string, string> $groups group ID => button label
     */
    public function buttons(Project $project, array $groups): string
    {
        if (
            $this->email === null
            || !$project->status->current()
            || !\current_user_can(Capabilities::EMAIL_SEND, $project->id)
        ) {
            return '';
        }

        $html = '';
        foreach ($groups as $groupId => $label) {
            $html .= '<a class="button" href="' . \esc_url($this->email->composeUrl([$groupId])) . '">'
                . \esc_html($label) . '</a> ';
        }

        return $html;
    }
}
