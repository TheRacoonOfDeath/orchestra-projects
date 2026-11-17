<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

use Orchestra\Core\Authorization\Roles;
use Orchestra\Members\Member;
use Orchestra\Members\MembersService;
use Orchestra\Projects\Field\ProjectFields;

/**
 * Saves the dates, staff and project fields of a project, from the project editor in wp-admin or the project form
 * on the projects page. The caller checks that the user may edit the project.
 */
final class ProjectFormHandler
{
    public const FIELD_STAFF = 'orchestra_projects_staff';
    public const FIELD_FIELDS = 'orchestra_projects_fields';
    /** Set when the form had the choice of project fields. */
    public const FIELD_FIELDS_SHOWN = 'orchestra_projects_fields_shown';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly StaffRepository $staff,
        private readonly MembersService $members,
        private readonly ProjectFields $fields,
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
            $input[ProjectPostType::META_CONCERT_DATE] ?? [],
            $input[ProjectPostType::META_DEADLINE] ?? ''
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

        if (($input[self::FIELD_FIELDS_SHOWN] ?? null) === '1') {
            $ids = \is_array($input[self::FIELD_FIELDS] ?? null) ? $input[self::FIELD_FIELDS] : [];
            $this->fields->select($projectId, \array_values(\array_filter($ids, 'is_string')));
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
