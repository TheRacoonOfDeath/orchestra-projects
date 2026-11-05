<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

use Orchestra\Projects\Database\Row;
use Orchestra\Projects\Database\Schema;
use wpdb;

/**
 * The assignments the members of a project were last informed about (table
 * `orchestra_projects_informed`), see {@see AssignmentChangeService}. The post meta
 * `orchestra_projects_informed` marks that a snapshot exists, also an empty one.
 */
final class InformedRepository
{
    public const META_TAKEN = 'orchestra_projects_informed';

    public function __construct(private readonly wpdb $wpdb, private readonly Schema $schema)
    {
    }

    public function exists(int $projectId): bool
    {
        return \get_post_meta($projectId, self::META_TAKEN, true) === '1';
    }

    /**
     * @return array<int, array<int, int>> user ID => piece ID => part ID
     */
    public function snapshot(int $projectId): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare('SELECT * FROM %i WHERE project_id = %d', $this->schema->informedTable(), $projectId),
            ARRAY_A
        ));

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[Row::int($row, 'user_id')][Row::int($row, 'piece_id')] = Row::int($row, 'part_id');
        }

        return $snapshot;
    }

    /**
     * @param array<int, array<int, int>> $snapshot user ID => piece ID => part ID
     */
    public function replace(int $projectId, array $snapshot): void
    {
        $this->delete($projectId);
        foreach ($snapshot as $userId => $parts) {
            foreach ($parts as $pieceId => $partId) {
                $this->wpdb->insert(
                    $this->schema->informedTable(),
                    ['project_id' => $projectId, 'piece_id' => $pieceId, 'part_id' => $partId, 'user_id' => $userId],
                    ['%d', '%d', '%d', '%d']
                );
            }
        }
        \update_post_meta($projectId, self::META_TAKEN, '1');
    }

    public function delete(int $projectId): void
    {
        $this->wpdb->delete($this->schema->informedTable(), ['project_id' => $projectId]);
        \delete_post_meta($projectId, self::META_TAKEN);
    }

    /**
     * A deleted user is no longer informed about anything.
     */
    public function deleteForUser(int $userId): void
    {
        $this->wpdb->delete($this->schema->informedTable(), ['user_id' => $userId]);
    }
}
