<?php

declare(strict_types=1);

namespace Orchestra\Projects\Privacy;

use Orchestra\Library\LibraryService;
use Orchestra\Projects\Assignment\AssignmentRepository;
use Orchestra\Projects\Participation\ParticipationRepository;
use Orchestra\Projects\Project\ProjectRepository;
use WP_User;

/**
 * Exports participation and assignments with the WordPress privacy tools.
 *
 * They are the orchestra's history, so the eraser keeps them. When the account is deleted, they
 * are anonymized ({@see \Orchestra\Projects\Project\ProjectCleanup::anonymizeUser()}).
 */
final class PersonalData
{
    private const ID = 'orchestra-projects';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ParticipationRepository $participations,
        private readonly AssignmentRepository $assignments,
        private readonly LibraryService $library,
    ) {
    }

    public function register(): void
    {
        \add_filter('wp_privacy_personal_data_exporters', [$this, 'addExporter']);
        \add_filter('wp_privacy_personal_data_erasers', [$this, 'addEraser']);
        \add_action('admin_init', [$this, 'addPolicyText']);
    }

    /**
     * @internal Hooked to `wp_privacy_personal_data_exporters`.
     * @param array<mixed> $exporters
     * @return array<mixed>
     */
    public function addExporter(array $exporters): array
    {
        $exporters[self::ID] = [
            'exporter_friendly_name' => \__('Orchestra projects', 'orchestra-projects'),
            'callback' => [$this, 'export'],
        ];

        return $exporters;
    }

    /**
     * @internal Hooked to `wp_privacy_personal_data_erasers`.
     * @param array<mixed> $erasers
     * @return array<mixed>
     */
    public function addEraser(array $erasers): array
    {
        $erasers[self::ID] = [
            'eraser_friendly_name' => \__('Orchestra projects', 'orchestra-projects'),
            'callback' => [$this, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $user = \get_user_by('email', $email);
        if (!$user instanceof WP_User) {
            return ['data' => [], 'done' => true];
        }

        $data = [];
        foreach ($this->participations->forUser($user->ID) as $participation) {
            $data[] = [
                'group_id' => self::ID . '-participation',
                'group_label' => \__('Project participation', 'orchestra-projects'),
                'item_id' => 'orchestra-participation-' . $participation->id,
                'data' => [
                    [
                        'name' => \__('Project', 'orchestra-projects'),
                        'value' => $this->projectName($participation->projectId),
                    ],
                    ['name' => \__('Participation', 'orchestra-projects'), 'value' => $participation->status->label()],
                    ['name' => \__('Responded', 'orchestra-projects'), 'value' => $participation->respondedAt ?? ''],
                    [
                        'name' => \__('Fee paid', 'orchestra-projects'),
                        'value' => $participation->feePaid
                            ? \__('Yes', 'orchestra-projects')
                            : \__('No', 'orchestra-projects'),
                    ],
                    [
                        'name' => \__('Your comment', 'orchestra-projects'),
                        'value' => $participation->memberComment ?? '',
                    ],
                    // Not shown to the member on the site, but data about them.
                    [
                        'name' => \__('Organizer note', 'orchestra-projects'),
                        'value' => $participation->organizerNote ?? '',
                    ],
                ],
            ];
        }
        foreach ($this->assignments->forUser($user->ID) as $assignment) {
            $data[] = [
                'group_id' => self::ID . '-assignments',
                'group_label' => \__('Assigned parts', 'orchestra-projects'),
                'item_id' => 'orchestra-assignment-' . $assignment->id,
                'data' => [
                    [
                        'name' => \__('Project', 'orchestra-projects'),
                        'value' => $this->projectName($assignment->projectId),
                    ],
                    [
                        'name' => \__('Piece', 'orchestra-projects'),
                        'value' => $this->library->piece($assignment->pieceId)->title ?? '',
                    ],
                    [
                        'name' => \__('Part', 'orchestra-projects'),
                        'value' => $this->library->part($assignment->partId)->name ?? '',
                    ],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        $user = \get_user_by('email', $email);
        $retained = $user instanceof WP_User
            && ($this->participations->forUser($user->ID) !== [] || $this->assignments->forUser($user->ID) !== []);

        return [
            'items_removed' => false,
            'items_retained' => $retained,
            'messages' => $retained
                ? [\__(
                    'Participation and assigned parts are kept as history and anonymized when the account is deleted.',
                    'orchestra-projects'
                )]
                : [],
            'done' => true,
        ];
    }

    /**
     * @internal Hooked to `admin_init`.
     */
    public function addPolicyText(): void
    {
        if (!\function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $sentences = [
            \__('For each project, we store whether members accepted or declined.', 'orchestra-projects'),
            \__(
                'Members can add a comment for the organizers, and organizers can add a note; only organizers'
                    . ' see them.',
                'orchestra-projects'
            ),
            \__('We store which parts members play in each project, to plan future projects.', 'orchestra-projects'),
            \__('It is kept after members leave and anonymized when accounts are deleted.', 'orchestra-projects'),
        ];
        \wp_add_privacy_policy_content(
            \__('Orchestra Projects', 'orchestra-projects'),
            '<p>' . \esc_html(\implode(' ', $sentences)) . '</p>'
        );
    }

    private function projectName(int $projectId): string
    {
        return $this->projects->find($projectId)->title ?? '#' . $projectId;
    }
}
