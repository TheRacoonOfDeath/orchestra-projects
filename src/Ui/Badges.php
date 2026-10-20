<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Html;
use Orchestra\Projects\Assignment\AssignmentService;
use Orchestra\Projects\Participation\ParticipationStatus;
use Orchestra\Projects\Project\ProjectStatus;

final class Badges
{
    public static function project(ProjectStatus $status): string
    {
        return Html::badge($status->label(), match ($status) {
            ProjectStatus::Open => 'info',
            ProjectStatus::Preparation => 'warning',
            ProjectStatus::Completed => 'success',
            ProjectStatus::Draft => '',
        });
    }

    /**
     * The label of a part the member plays for the first time: a yellow starburst.
     */
    public static function newPart(): string
    {
        Assets::enqueue();

        return '<span class="orchestra-projects-new">' . \esc_html(AssignmentService::newPartLabel()) . '</span>';
    }

    public static function participation(ParticipationStatus $status): string
    {
        return Html::badge($status->label(), match ($status) {
            ParticipationStatus::Open => 'warning',
            ParticipationStatus::Accepted => 'success',
            ParticipationStatus::Declined => 'danger',
        });
    }
}
