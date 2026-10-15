<?php

declare(strict_types=1);

namespace Orchestra\Projects\Program;

use Orchestra\Projects\Database\Row;
use Orchestra\Projects\Database\Schema;
use wpdb;

/**
 * The ordered pieces of each project's program.
 */
final class ProgramRepository
{
    public function __construct(private readonly wpdb $wpdb, private readonly Schema $schema)
    {
    }

    /**
     * @return list<int> piece IDs in program order
     */
    public function pieceIds(int $projectId): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT piece_id FROM %i WHERE project_id = %d ORDER BY position, id',
                $this->schema->programTable(),
                $projectId
            ),
            ARRAY_A
        ));

        return \array_map(static fn (array $row): int => Row::int($row, 'piece_id'), $rows);
    }

    public function contains(int $projectId, int $pieceId): bool
    {
        return \in_array($pieceId, $this->pieceIds($projectId), true);
    }

    /**
     * @return list<int> IDs of the projects whose program contains the piece
     */
    public function projectIdsWithPiece(int $pieceId): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT project_id FROM %i WHERE piece_id = %d',
                $this->schema->programTable(),
                $pieceId
            ),
            ARRAY_A
        ));

        return \array_map(static fn (array $row): int => Row::int($row, 'project_id'), $rows);
    }

    public function add(int $projectId, int $pieceId): void
    {
        $this->wpdb->insert(
            $this->schema->programTable(),
            ['project_id' => $projectId, 'piece_id' => $pieceId, 'position' => \count($this->pieceIds($projectId))],
            ['%d', '%d', '%d']
        );
    }

    public function remove(int $projectId, int $pieceId): void
    {
        $this->wpdb->delete($this->schema->programTable(), ['project_id' => $projectId, 'piece_id' => $pieceId]);
        $this->reorder($projectId, $this->pieceIds($projectId));
    }

    /**
     * @param list<int> $pieceIds The program's piece IDs in their new order
     */
    public function reorder(int $projectId, array $pieceIds): void
    {
        foreach ($pieceIds as $position => $pieceId) {
            $this->wpdb->update(
                $this->schema->programTable(),
                ['position' => $position],
                ['project_id' => $projectId, 'piece_id' => $pieceId],
                ['%d'],
                ['%d', '%d']
            );
        }
    }

    public function deleteForProject(int $projectId): void
    {
        $this->wpdb->delete($this->schema->programTable(), ['project_id' => $projectId]);
    }
}
