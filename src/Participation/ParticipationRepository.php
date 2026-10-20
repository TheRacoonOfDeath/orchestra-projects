<?php

declare(strict_types=1);

namespace Orchestra\Projects\Participation;

use Orchestra\Projects\Database\Row;
use Orchestra\Projects\Database\Schema;
use wpdb;

final class ParticipationRepository
{
    public function __construct(private readonly wpdb $wpdb, private readonly Schema $schema)
    {
    }

    public function find(int $participationId): ?Participation
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d',
                $this->schema->participationTable(),
                $participationId
            ),
            ARRAY_A
        );

        return \is_array($row) ? self::hydrate($row) : null;
    }

    public function findFor(int $projectId, int $userId): ?Participation
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE project_id = %d AND user_id = %d',
                $this->schema->participationTable(),
                $projectId,
                $userId
            ),
            ARRAY_A
        );

        return \is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @return list<Participation>
     */
    public function forProject(int $projectId, ?ParticipationStatus $status = null): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE project_id = %d ORDER BY id',
                $this->schema->participationTable(),
                $projectId
            ),
            ARRAY_A
        ));
        $participations = \array_map(self::hydrate(...), $rows);

        return $status === null
            ? $participations
            : \array_values(\array_filter(
                $participations,
                static fn (Participation $p): bool => $p->status === $status
            ));
    }

    /**
     * @return list<Participation>
     */
    public function forUser(int $userId): array
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE user_id = %d ORDER BY id',
                $this->schema->participationTable(),
                $userId
            ),
            ARRAY_A
        ));

        return \array_map(self::hydrate(...), $rows);
    }

    /**
     * Creates Open records for the users who have none for the project yet.
     *
     * @param list<int> $userIds
     * @return int number of created records
     */
    public function createMissing(int $projectId, array $userIds): int
    {
        $existing = \array_flip(\array_map(
            static fn (Participation $p): int => $p->userId ?? 0,
            $this->forProject($projectId)
        ));

        $created = 0;
        foreach (\array_unique($userIds) as $userId) {
            if ($userId <= 0 || isset($existing[$userId])) {
                continue;
            }
            $this->wpdb->insert(
                $this->schema->participationTable(),
                ['project_id' => $projectId, 'user_id' => $userId, 'status' => ParticipationStatus::Open->value],
                ['%d', '%d', '%s']
            );
            $created++;
        }

        return $created;
    }

    public function setStatus(int $participationId, ParticipationStatus $status, int $changedBy, bool $isResponse): void
    {
        $now = \current_time('mysql', true);
        $data = ['status' => $status->value, 'changed_by' => $changedBy, 'changed_at' => $now];
        if ($isResponse) {
            $data['responded_at'] = $now;
        }

        $this->wpdb->update($this->schema->participationTable(), $data, ['id' => $participationId]);
    }

    public function deleteForProject(int $projectId): void
    {
        $this->wpdb->delete($this->schema->participationTable(), ['project_id' => $projectId]);
    }

    /**
     * Keeps the records for the history but removes the reference to the user.
     */
    public function anonymizeUser(int $userId): void
    {
        $this->execute($this->wpdb->prepare(
            'UPDATE %i SET user_id = NULL WHERE user_id = %d',
            $this->schema->participationTable(),
            $userId
        ));
        $this->execute($this->wpdb->prepare(
            'UPDATE %i SET changed_by = NULL WHERE changed_by = %d',
            $this->schema->participationTable(),
            $userId
        ));
    }

    /**
     * @param array<mixed> $row
     */
    private static function hydrate(array $row): Participation
    {
        return new Participation(
            Row::int($row, 'id'),
            Row::int($row, 'project_id'),
            Row::nullableInt($row, 'user_id'),
            ParticipationStatus::tryFrom(Row::string($row, 'status')) ?? ParticipationStatus::Open,
            ($row['responded_at'] ?? null) === null ? null : Row::string($row, 'responded_at'),
            Row::nullableInt($row, 'changed_by'),
            ($row['changed_at'] ?? null) === null ? null : Row::string($row, 'changed_at'),
        );
    }

    private function execute(?string $query): void
    {
        if ($query !== null) {
            $this->wpdb->query($query);
        }
    }
}
