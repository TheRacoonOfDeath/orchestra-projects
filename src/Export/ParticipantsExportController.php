<?php

declare(strict_types=1);

namespace Orchestra\Projects\Export;

use Orchestra\Members\Capabilities as MembersCapabilities;
use Orchestra\Projects\Capabilities;
use Orchestra\Projects\Project\ProjectRepository;

/**
 * Downloads the accepted participants of a project as a CSV file
 * (admin-post.php?action=orchestra_projects_export_participants&project=ID).
 *
 * Like the library downloads, the export changes nothing and needs no nonce.
 */
final class ParticipantsExportController
{
    public const ACTION = 'orchestra_projects_export_participants';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ParticipantsExport $export,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        \add_action('admin_post_nopriv_' . self::ACTION, 'auth_redirect');
    }

    public static function url(int $projectId): string
    {
        return \add_query_arg(['action' => self::ACTION, 'project' => $projectId], \admin_url('admin-post.php'));
    }

    /**
     * @internal Hooked to `admin_post_orchestra_projects_export_participants`.
     */
    public function handle(): never
    {
        $projectId = \absint(\is_string($_GET['project'] ?? null) ? $_GET['project'] : 0);
        $project = $this->projects->find($projectId);
        if ($project === null || !\current_user_can(Capabilities::PARTICIPATION_EXPORT)) {
            self::forbidden();
        }

        // The profiles are sensitive: export them only if every one of them may be read.
        $userIds = $this->export->userIds($project->id);
        foreach ($userIds as $userId) {
            if (!\current_user_can(MembersCapabilities::PROFILE_READ, $userId)) {
                self::forbidden();
            }
        }

        $csv = Csv::document($this->export->rows($userIds), Csv::separator());
        /* translators: %s: project name */
        $fileName = \sprintf(\__('%s - participants.csv', 'orchestra-projects'), $project->title);

        \nocache_headers();
        \header('Content-Type: text/csv; charset=UTF-8');
        \header('Content-Length: ' . (string) \strlen($csv));
        \header('Content-Disposition: ' . self::attachment($fileName));
        \header('X-Content-Type-Options: nosniff');
        \header('Cache-Control: private, no-store, max-age=0');
        echo $csv; // A file download, not HTML.
        exit;
    }

    /**
     * An `attachment` header value that works for non-ASCII file names (RFC 6266).
     */
    private static function attachment(string $fileName): string
    {
        $fileName = \str_replace(["\r", "\n", '/', '\\'], ['', '', '-', '-'], $fileName);
        $fallback = \str_replace(['"', '%'], '_', (string) \preg_replace('/[^\x20-\x7E]/', '_', $fileName));

        return \sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, \rawurlencode($fileName));
    }

    private static function forbidden(): never
    {
        \wp_die(\esc_html__('You are not allowed to do this.', 'orchestra-projects'), 403);
    }
}
