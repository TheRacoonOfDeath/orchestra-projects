<?php

declare(strict_types=1);

namespace Orchestra\Projects\Field;

use Orchestra\Projects\Project\ProjectPostType;

/**
 * The project fields, defined centrally by the organizers (an option, in display order), and the
 * fields each project shows (post meta with their IDs). A field that a project no longer shows keeps
 * its answers there, so ticking it again brings them back.
 */
final class ProjectFields
{
    public const OPTION = 'orchestra_projects_fields';

    private const ID_MAX_LENGTH = 40;

    /** @var list<ProjectField>|null */
    private ?array $cache = null;

    /**
     * @return list<ProjectField> in display order
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = \get_option(self::OPTION);
        $fields = [];
        foreach (\is_array($stored) ? $stored : [] as $data) {
            $field = \is_array($data) ? ProjectField::fromArray($data) : null;
            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $this->cache = $fields;
    }

    /**
     * @param list<ProjectField> $fields in display order
     */
    public function save(array $fields): void
    {
        \update_option(
            self::OPTION,
            \array_map(static fn (ProjectField $field): array => $field->toArray(), $fields),
            false
        );
        $this->cache = $fields;
    }

    /**
     * The fields the project shows, in the central order.
     *
     * @return list<ProjectField>
     */
    public function forProject(int $projectId): array
    {
        $ids = $this->selectedIds($projectId);

        return \array_values(\array_filter(
            $this->all(),
            static fn (ProjectField $field): bool => \in_array($field->id, $ids, true)
        ));
    }

    /**
     * @return list<string> the IDs of the fields the project shows
     */
    public function selectedIds(int $projectId): array
    {
        $stored = \get_post_meta($projectId, ProjectPostType::META_FIELDS, true);

        return \is_array($stored) ? \array_values(\array_filter($stored, 'is_string')) : [];
    }

    /**
     * Chooses the fields the project shows; unknown IDs are ignored.
     *
     * @param list<string> $ids
     */
    public function select(int $projectId, array $ids): void
    {
        $known = \array_map(static fn (ProjectField $field): string => $field->id, $this->all());
        $ids = \array_values(\array_intersect($known, $ids));
        if ($ids === []) {
            \delete_post_meta($projectId, ProjectPostType::META_FIELDS);
        } else {
            \update_post_meta($projectId, ProjectPostType::META_FIELDS, $ids);
        }
    }

    /**
     * Removes a deleted field from every project, so that a new field with the same ID is not shown
     * in them.
     */
    public function forget(string $fieldId): void
    {
        $projectIds = \get_posts([
            'post_type' => ProjectPostType::NAME,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => ProjectPostType::META_FIELDS,
        ]);
        foreach ($projectIds as $projectId) {
            $projectId = (int) $projectId;
            $ids = $this->selectedIds($projectId);
            if (\in_array($fieldId, $ids, true)) {
                $this->select($projectId, \array_values(\array_diff($ids, [$fieldId])));
            }
        }
    }

    /**
     * A new, unused field ID derived from the label, e.g. "t_shirt_size" for "T-shirt size".
     *
     * @param list<string> $taken
     */
    public static function newId(string $label, array $taken): string
    {
        $base = \strtolower(\remove_accents($label));
        $base = \trim((string) \preg_replace('/[^a-z0-9]+/', '_', $base), '_');
        $base = \rtrim(\substr($base, 0, self::ID_MAX_LENGTH), '_');
        if ($base === '') {
            $base = 'field';
        }

        $id = $base;
        for ($number = 2; \in_array($id, $taken, true); $number++) {
            $id = $base . '_' . $number;
        }

        return $id;
    }
}
