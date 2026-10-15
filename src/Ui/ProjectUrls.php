<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Closure;

/**
 * The links of the project views, which differ between wp-admin and the projects page.
 */
final class ProjectUrls
{
    /**
     * @param Closure(): string $list
     * @param Closure(int $projectId, string $tab): string $project
     * @param Closure(?int $projectId): string $edit The form for a new (null) or existing project
     */
    public function __construct(
        private readonly Closure $list,
        private readonly Closure $project,
        private readonly Closure $edit,
    ) {
    }

    public function list(): string
    {
        return ($this->list)();
    }

    public function project(int $projectId, string $tab = ProjectView::OVERVIEW): string
    {
        return ($this->project)($projectId, $tab);
    }

    public function edit(?int $projectId): string
    {
        return ($this->edit)($projectId);
    }
}
