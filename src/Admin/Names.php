<?php

declare(strict_types=1);

namespace Orchestra\Projects\Admin;

use Orchestra\Members\MembersService;

/**
 * Display names of users, including deleted and inactive ones.
 */
final class Names
{
    public function __construct(private readonly MembersService $members)
    {
    }

    public function of(?int $userId): string
    {
        if ($userId === null) {
            return \__('Former member', 'orchestra-projects');
        }
        $user = \get_userdata($userId);
        if ($user === false) {
            return \__('Former member', 'orchestra-projects');
        }

        return $this->members->isActive($userId)
            ? $user->display_name
            /* translators: %s: member name */
            : \sprintf(\__('%s (inactive)', 'orchestra-projects'), $user->display_name);
    }

    /**
     * @param list<int> $userIds
     */
    public function list(array $userIds): string
    {
        return $userIds === [] ? '—' : \implode(', ', \array_map(fn (int $id): string => $this->of($id), $userIds));
    }
}
