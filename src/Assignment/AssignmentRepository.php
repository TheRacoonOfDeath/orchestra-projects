<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

use Orchestra\Projects\Database\Row;
use Orchestra\Projects\Database\Schema;
use wpdb;

final class AssignmentRepository
{
    public function __construct(private readonly wpdb $wpdb, private readonly Schema $schema)
    {
    }

    public function find(int $assignmentId): ?Assignment
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->schema->assignmentsTable(), $assignmentId),
            ARRAY_A
        );

        return \is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @return list<Assignment>
     */
    public function forProject(int $projectId): array
    {
        return $this->where('project_id', $projectId);
    }

    /**
     * @return list<Assignment> in all projects, in the order they were made
     */
    public function forPiece(int $pieceId): array
    {
        return $this->where('piece_id', $pieceId);
    }

    /**
     * @return list<Assignment> in all projects
     */
    public function forPart(int $partId): array
    {
        return $this->where('part_id', $partId);
    }

    /**
     * @return list<Assignment> in all projects
     */
    public function forUser(int $userId): array
    {
        return $this->where('user_id', $userId);
    }

    /**
     * @return list<Assignment>
     */
    public function forProjectAndUser(int $projectId, int $userId): array
    {
        return \array_values(\array_filter(
            $this->forProject($projectId),
            static fn (Assignment $a): bool => $a->userId === $userId
        ));
    }

    public function insert(int $projectId, int $pieceId, int $partId, int $userId, int $createdBy): void
    {
        $this->wpdb->insert($this->schema->assignmentsTable(), [
            'project_id' => $projectId,
            'piece_id' => $pieceId,
            'part_id' => $partId,
            'user_id' => $userId,
            'created_by' => $createdBy,
            'created_at' => \current_time('mysql', true),
        ], ['%d', '%d', '%d', '%d', '%d', '%s']);
    }

    public function delete(int $assignmentId): void
    {
        $this->wpdb->delete($this->schema->assignmentsTable(), ['id' => $assignmentId]);
    }

    public function deleteForProjectAndPiece(int $projectId, int $pieceId): void
    {
        $this->wpdb->delete($this->schema->assignmentsTable(), ['project_id' => $projectId, 'piece_id' => $pieceId]);
    }

    public function deleteForProjectAndUser(int $projectId, int $userId): void
    {
        $this->wpdb->delete($this->schema->assignmentsTable(), ['project_id' => $projectId, 'user_id' => $userId]);
    }

    public function deleteForProject(int $projectId): void
    {
        $this->wpdb->delete($this->schema->assignmentsTable(), ['project_id' => $projectId]);
    }

    /**
     * Keeps the assignments for the history but removes the reference to the user.
     */
    public function anonymizeUser(int $userId): void
    {
        $this->execute($this->wpdb->prepare(
            'UPDATE %i SET user_id = NULL WHERE user_id = %d',
            $this->schema->assignmentsTable(),
            $userId
        ));
    }

    /**
     * @return list<Assignment>
     */
    private function where(string $column, int $value): array
    {
        $column = \in_array($column, ['project_id', 'piece_id', 'part_id', 'user_id'], true) ? $column : 'id';
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE %i = %d ORDER BY id',
                $this->schema->assignmentsTable(),
                $column,
                $value
            ),
            ARRAY_A
        ));

        return \array_map(self::hydrate(...), $rows);
    }

    /**
     * @param array<mixed> $row
     */
    private static function hydrate(array $row): Assignment
    {
        return new Assignment(
            Row::int($row, 'id'),
            Row::int($row, 'project_id'),
            Row::int($row, 'piece_id'),
            Row::int($row, 'part_id'),
            Row::nullableInt($row, 'user_id'),
        );
    }

    private function execute(?string $query): void
    {
        if ($query !== null) {
            $this->wpdb->query($query);
        }
    }
}
