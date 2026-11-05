<?php

declare(strict_types=1);

namespace Orchestra\Projects\Assignment;

/**
 * Compares two states of a project's assignments. Only the result counts: a part that was changed
 * and changed back is no change.
 */
final class AssignmentChanges
{
    /**
     * @param array<int, array<int, int>> $before user ID => piece ID => part ID
     * @param array<int, array<int, int>> $after user ID => piece ID => part ID
     * @return list<PartChange> by user ID, then piece ID
     */
    public static function between(array $before, array $after): array
    {
        $userIds = \array_unique([...\array_keys($before), ...\array_keys($after)]);
        \sort($userIds);

        $changes = [];
        foreach ($userIds as $userId) {
            $pieceIds = \array_unique([...\array_keys($before[$userId] ?? []), ...\array_keys($after[$userId] ?? [])]);
            \sort($pieceIds);
            foreach ($pieceIds as $pieceId) {
                $old = $before[$userId][$pieceId] ?? null;
                $new = $after[$userId][$pieceId] ?? null;
                if ($old !== $new) {
                    $changes[] = new PartChange($userId, $pieceId, $old, $new);
                }
            }
        }

        return $changes;
    }

    /**
     * @param list<Assignment> $assignments
     * @return array<int, array<int, int>> user ID => piece ID => part ID, without deleted users
     */
    public static function map(array $assignments): array
    {
        $map = [];
        foreach ($assignments as $assignment) {
            if ($assignment->userId !== null) {
                $map[$assignment->userId][$assignment->pieceId] = $assignment->partId;
            }
        }

        return $map;
    }

    /**
     * Identifies the changes, to notice when they changed while someone was looking at them.
     *
     * @param list<PartChange> $changes
     */
    public static function fingerprint(array $changes): string
    {
        return \md5(\implode(';', \array_map(
            static fn (PartChange $c): string
                => \implode(',', [$c->userId, $c->pieceId, $c->oldPartId ?? '', $c->newPartId ?? '']),
            $changes
        )));
    }

    /**
     * @param list<PartChange> $changes
     * @return array<int, list<PartChange>> user ID => their changes
     */
    public static function byUser(array $changes): array
    {
        $byUser = [];
        foreach ($changes as $change) {
            $byUser[$change->userId][] = $change;
        }

        return $byUser;
    }
}
