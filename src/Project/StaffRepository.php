<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

use Orchestra\Projects\Database\Row;
use Orchestra\Projects\Database\Schema;
use wpdb;

/**
 * The conductors and organizers who run a project. Informational only: permissions are global.
 */
final class StaffRepository
{
    public const CONDUCTOR = 'conductor';
    public const ORGANIZER = 'organizer';

    public function __construct(private readonly wpdb $wpdb, private readonly Schema $schema)
    {
    }

    /**
     * @return list<int>
     */
    public function userIds(int $projectId, string $role): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT user_id FROM %i WHERE project_id = %d AND role = %s ORDER BY id',
                $this->schema->staffTable(),
                $projectId,
                $role
            ),
            ARRAY_A
        ));

        return \array_map(static fn (array $row): int => Row::int($row, 'user_id'), $rows);
    }

    /**
     * @param list<int> $userIds
     */
    public function replace(int $projectId, string $role, array $userIds): void
    {
        $this->wpdb->delete($this->schema->staffTable(), ['project_id' => $projectId, 'role' => $role]);
        foreach (\array_unique($userIds) as $userId) {
            $this->wpdb->insert(
                $this->schema->staffTable(),
                ['project_id' => $projectId, 'user_id' => $userId, 'role' => $role],
                ['%d', '%d', '%s']
            );
        }
    }

    public function deleteForProject(int $projectId): void
    {
        $this->wpdb->delete($this->schema->staffTable(), ['project_id' => $projectId]);
    }

    /**
     * @return list<array{projectId: int, role: string}> the projects the user runs
     */
    public function forUser(int $userId): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT project_id, role FROM %i WHERE user_id = %d ORDER BY id',
                $this->schema->staffTable(),
                $userId
            ),
            ARRAY_A
        ));

        return \array_map(
            static fn (array $row): array => [
                'projectId' => Row::int($row, 'project_id'),
                'role' => Row::string($row, 'role'),
            ],
            $rows
        );
    }

    /**
     * @return int the number of deleted rows
     */
    public function deleteForUser(int $userId): int
    {
        $deleted = $this->wpdb->delete($this->schema->staffTable(), ['user_id' => $userId]);

        return \is_int($deleted) ? $deleted : 0;
    }
}
