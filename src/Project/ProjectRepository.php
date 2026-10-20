<?php

declare(strict_types=1);

namespace Orchestra\Projects\Project;

use WP_Post;

final class ProjectRepository
{
    public function find(int $projectId): ?Project
    {
        $post = $projectId > 0 ? \get_post($projectId) : null;
        if (
            !$post instanceof WP_Post
            || $post->post_type !== ProjectPostType::NAME
            || $post->post_status !== 'publish'
        ) {
            return null;
        }

        return self::hydrate($post);
    }

    /**
     * All saved projects, newest first (by last concert, end or start date).
     *
     * @param list<ProjectStatus> $statuses Only projects in these statuses; all if empty
     * @return list<Project>
     */
    public function all(array $statuses = []): array
    {
        $posts = \get_posts([
            'post_type' => ProjectPostType::NAME,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'DESC',
        ]);

        $projects = [];
        foreach ($posts as $post) {
            $project = self::hydrate($post);
            if ($statuses === [] || \in_array($project->status, $statuses, true)) {
                $projects[] = $project;
            }
        }
        \usort($projects, static fn (Project $a, Project $b): int => [$b->sortDate() ?? '', $b->id]
            <=> [$a->sortDate() ?? '', $a->id]);

        return $projects;
    }

    public function setStatus(int $projectId, ProjectStatus $status): void
    {
        \update_post_meta($projectId, ProjectPostType::META_STATUS, $status->value);
    }

    public function saveDates(int $projectId, ProjectDates $dates): void
    {
        foreach (
            [
            ProjectPostType::META_START_DATE => $dates->startDate,
            ProjectPostType::META_END_DATE => $dates->endDate,
            ] as $key => $value
        ) {
            if ($value === null) {
                \delete_post_meta($projectId, $key);
            } else {
                \update_post_meta($projectId, $key, $value);
            }
        }

        // One meta entry per concert.
        \delete_post_meta($projectId, ProjectPostType::META_CONCERT_DATE);
        foreach ($dates->concertDates as $concertDate) {
            \add_post_meta($projectId, ProjectPostType::META_CONCERT_DATE, $concertDate);
        }
    }

    private static function hydrate(WP_Post $post): Project
    {
        return new Project(
            $post->ID,
            $post->post_title,
            $post->post_content,
            ProjectStatus::fromStored(self::meta($post->ID, ProjectPostType::META_STATUS)),
            self::meta($post->ID, ProjectPostType::META_START_DATE),
            self::meta($post->ID, ProjectPostType::META_END_DATE),
            self::concertDates($post->ID),
        );
    }

    /**
     * @return list<string>
     */
    private static function concertDates(int $postId): array
    {
        $values = \get_post_meta($postId, ProjectPostType::META_CONCERT_DATE);
        $dates = \array_values(\array_unique(\array_filter(
            \is_array($values) ? $values : [],
            static fn (mixed $value): bool => \is_string($value) && $value !== ''
        )));
        \sort($dates);

        return $dates;
    }

    private static function meta(int $postId, string $key): ?string
    {
        $value = \get_post_meta($postId, $key, true);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
