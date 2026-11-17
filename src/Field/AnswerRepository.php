<?php

declare(strict_types=1);

namespace Orchestra\Projects\Field;

use Orchestra\Projects\Database\Row;
use Orchestra\Projects\Database\Schema;
use wpdb;

/**
 * The answers of the participants to the project fields (table `orchestra_projects_answers`). Empty
 * answers are not stored.
 */
final class AnswerRepository
{
    public function __construct(private readonly wpdb $wpdb, private readonly Schema $schema)
    {
    }

    /**
     * @return array<string, string> field ID => answer
     */
    public function of(int $projectId, int $userId): array
    {
        return $this->forProject($projectId)[$userId] ?? [];
    }

    /**
     * @return array<int, array<string, string>> user ID => field ID => answer
     */
    public function forProject(int $projectId): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT user_id, field_id, answer FROM %i WHERE project_id = %d ORDER BY id',
                $this->schema->answersTable(),
                $projectId
            ),
            ARRAY_A
        ));
        $answers = [];
        foreach ($rows as $row) {
            $answers[Row::int($row, 'user_id')][Row::string($row, 'field_id')] = Row::string($row, 'answer');
        }

        return $answers;
    }

    /**
     * @return array<int, array<string, string>> project ID => field ID => answer
     */
    public function forUser(int $userId): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT project_id, field_id, answer FROM %i WHERE user_id = %d ORDER BY id',
                $this->schema->answersTable(),
                $userId
            ),
            ARRAY_A
        ));
        $answers = [];
        foreach ($rows as $row) {
            $answers[Row::int($row, 'project_id')][Row::string($row, 'field_id')] = Row::string($row, 'answer');
        }

        return $answers;
    }

    /**
     * Stores the answers of a member; an empty answer deletes the stored one.
     *
     * @param array<string, string> $answers field ID => answer
     */
    public function save(int $projectId, int $userId, array $answers): void
    {
        foreach ($answers as $fieldId => $answer) {
            if ($answer === '') {
                $this->wpdb->delete(
                    $this->schema->answersTable(),
                    ['project_id' => $projectId, 'user_id' => $userId, 'field_id' => $fieldId],
                    ['%d', '%d', '%s']
                );
                continue;
            }
            $this->wpdb->replace(
                $this->schema->answersTable(),
                ['project_id' => $projectId, 'user_id' => $userId, 'field_id' => $fieldId, 'answer' => $answer],
                ['%d', '%d', '%s', '%s']
            );
        }
    }

    public function deleteForProject(int $projectId): void
    {
        $this->wpdb->delete($this->schema->answersTable(), ['project_id' => $projectId], ['%d']);
    }

    /**
     * @return int the number of deleted answers
     */
    public function deleteForUser(int $userId): int
    {
        $deleted = $this->wpdb->delete($this->schema->answersTable(), ['user_id' => $userId], ['%d']);

        return \is_int($deleted) ? $deleted : 0;
    }

    public function deleteForField(string $fieldId): void
    {
        $this->wpdb->delete($this->schema->answersTable(), ['field_id' => $fieldId], ['%s']);
    }
}
