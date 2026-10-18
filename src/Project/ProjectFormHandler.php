<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

use Orchestra\Core\Authorization\Roles;
use Orchestra\Members\Member;
use Orchestra\Members\MembersService;

/**
 * Saves the dates and staff of a project, from the project editor in wp-admin or the project form
 * on the projects page. The caller checks that the user may edit the project.
 */
final class ProjectFormHandler
{
    public const FIELD_STAFF = 'orchestra_projects_staff';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly StaffRepository $staff,
        private readonly MembersService $members,
    ) {
    }

    /**
     * @param array<mixed> $input Unslashed form data
     * @return list<string> error messages; the rest is saved
     */
    public function save(int $projectId, array $input): array
    {
        $dates = ProjectDates::fromInput(
            $input[ProjectPostType::META_START_DATE] ?? '',
            $input[ProjectPostType::META_END_DATE] ?? '',
            $input[ProjectPostType::META_CONCERT_DATE] ?? []
        );
        if ($dates->errors === []) {
            $this->projects->saveDates($projectId, $dates);
        }

        $submitted = \is_array($input[self::FIELD_STAFF] ?? null) ? $input[self::FIELD_STAFF] : [];
        foreach (self::staffRoles() as $staffRole => $role) {
            $candidates = \array_map(static fn (Member $member): int => $member->id, $this->members->members([$role]));
            $ids = \is_array($submitted[$staffRole] ?? null) ? $submitted[$staffRole] : [];
            $ids = \array_map('absint', \array_filter($ids, \is_scalar(...)));
            $this->staff->replace($projectId, $staffRole, \array_values(\array_intersect($ids, $candidates)));
        }

        return $dates->errors;
    }

    /**
     * @return array<string, string> staff role => the WordPress role of the candidates
     */
    public static function staffRoles(): array
    {
        return [StaffRepository::CONDUCTOR => Roles::CONDUCTOR, StaffRepository::ORGANIZER => Roles::ORGANIZER];
    }
}
