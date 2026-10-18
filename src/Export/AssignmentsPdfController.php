<?php

declare(strict_types=1);

namespace Orchestra\Projects\Export;

use Orchestra\Core\Pdf\Pdf;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Project\ProjectRepository;
use Orchestra\Projects\Ui\ProjectView;

/**
 * Downloads the assignments of a project as a PDF
 * (admin-post.php?action=orchestra_projects_assignments_pdf&project=ID), for everyone who can open
 * the assignments tab. Like the other downloads, it changes nothing and needs no nonce.
 */
final class AssignmentsPdfController
{
    public const ACTION = 'orchestra_projects_assignments_pdf';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly AssignmentsPdf $pdf,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        \add_action('admin_post_nopriv_' . self::ACTION, 'auth_redirect');
    }

    /**
     * Whether PDFs can be created: orchestra-core 0.8 or later.
     */
    public static function available(): bool
    {
        return \class_exists(Pdf::class);
    }

    public static function url(int $projectId): string
    {
        return \add_query_arg(['action' => self::ACTION, 'project' => $projectId], \admin_url('admin-post.php'));
    }

    /**
     * @internal Hooked to `admin_post_orchestra_projects_assignments_pdf`.
     */
    public function handle(): never
    {
        $projectId = \absint(\is_string($_GET['project'] ?? null) ? $_GET['project'] : 0);
        $project = $this->projects->find($projectId);
        if (
            $project === null
            || !ProjectView::canSee($project)
            || !\array_key_exists(ProjectView::ASSIGNMENTS, ProjectView::tabs($project))
        ) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-projects'), 403);
        }

        Pdf::download(
            $this->pdf->html($project),
            Pdf::fileName($project->title, \__('Assignments', 'orchestra-projects'))
        );
    }
}
