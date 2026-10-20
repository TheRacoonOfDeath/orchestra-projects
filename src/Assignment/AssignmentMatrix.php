<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

/**
 * The assignments of a project by member: one row per member, one column per piece of the program,
 * the part in each cell. Shown on the assignments tab and in the PDF.
 */
final class AssignmentMatrix
{
    /**
     * @param array<int, string> $players user ID => name of the accepted participants, in order
     * @param list<int> $pieceIds the program, in order
     * @param list<Assignment> $assignments the assignments of the project
     * @param array<int, string> $partNames part ID => name
     * @param array<int, true> $new IDs of the assignments to a part the member plays for the first time
     * @param \Closure(int): string $name the name of a user who is assigned but no longer accepted
     * @return list<array{name: string, cells: list<array{part: string, new: bool}|null>}> rows, the
     *     accepted participants first, then others with assignments; a cell is null without a part
     */
    public static function rows(
        array $players,
        array $pieceIds,
        array $assignments,
        array $partNames,
        array $new,
        \Closure $name,
    ): array {
        $parts = [];
        foreach ($assignments as $assignment) {
            if ($assignment->userId !== null) {
                $parts[$assignment->userId][$assignment->pieceId] = [
                    'part' => $partNames[$assignment->partId] ?? '#' . $assignment->partId,
                    'new' => isset($new[$assignment->id]),
                ];
            }
        }
        $names = $players;
        foreach (\array_keys($parts) as $userId) {
            $names[$userId] ??= $name($userId);
        }

        $rows = [];
        foreach ($names as $userId => $userName) {
            $rows[] = [
                'name' => $userName,
                'cells' => \array_map(static fn (int $pieceId): ?array => $parts[$userId][$pieceId] ?? null, $pieceIds),
            ];
        }

        return $rows;
    }
}
