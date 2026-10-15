# API for other plugins

```php
$projects = \Orchestra\Projects\Plugin::instance()->projects();
```

Check that the plugin is active first, e.g. with `class_exists(\Orchestra\Projects\Plugin::class)`
on `plugins_loaded` or later. The service does not check permissions. Do not access the tables or
meta of orchestra-projects directly.

## `ProjectsService`

| Method | Returns |
|--------|---------|
| `project(int $projectId): ?Project` | The project, or null |
| `projects(array $statuses = []): list<Project>` | Projects, newest first, optionally only with the given `ProjectStatus` values |
| `programPieceIds(int $projectId): list<int>` | Piece IDs of orchestra-library in program order |
| `participantIds(int $projectId, ?ParticipationStatus $status = null): list<int>` | Users with a participation record, optionally only with the status |
| `assignedUserIds(int $projectId, ?array $partIds = null): list<int>` | Users assigned in the project, optionally only to the given parts |
| `assignments(int $projectId): list<Assignment>` | All assignments of the project |

`Project` has `id`, `title`, `description`, `status` (`ProjectStatus`), `startDate`, `endDate` and
`concertDate` (Y-m-d or null). Deleted users are left out of the user ID lists; their
`Assignment::$userId` is null.

## Actions

| Action | Arguments | Fired |
|--------|-----------|-------|
| `orchestra_projects_status_changed` | `int $projectId, string $newStatus, string $previousStatus` | After the status of a project changed |

## Hooks used

orchestra-projects uses the filters `orchestra_library_piece_delete_blockers` and
`orchestra_library_part_delete_blockers`, and the action `orchestra_members_member_activated`.
