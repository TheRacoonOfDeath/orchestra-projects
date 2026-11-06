# API for other plugins

```php
$projects = \Orchestra\Projects\Plugin::instance()->projects();
```

Check that the plugin is active first, e.g. with `class_exists(\Orchestra\Projects\Plugin::class)`
on `plugins_loaded` or later. The service does not check permissions. Do not access the tables or
meta of orchestra-projects directly. It only reads, except for `setPart()`.

## `ProjectsService`

| Method | Returns |
|--------|---------|
| `project(int $projectId): ?Project` | The project, or null |
| `projects(array $statuses = []): list<Project>` | Projects, newest first, optionally only with the given `ProjectStatus` values |
| `programPieceIds(int $projectId): list<int>` | Piece IDs of orchestra-library in program order |
| `participantIds(int $projectId, ?ParticipationStatus $status = null): list<int>` | Users with a participation record, optionally only with the status |
| `assignedUserIds(int $projectId, ?array $partIds = null): list<int>` | Users assigned in the project, optionally only to the given parts |
| `assignments(int $projectId): list<Assignment>` | All assignments of the project |
| `playedParts(int $projectId, int $pieceId): array<int, array<int, bool>>` | Since 0.30: user ID => part ID => `true` for the part of the member's latest assignment of the piece in another project, `false` for parts they played in other projects before |
| `setPart(int $projectId, int $pieceId, int $userId, ?int $partId, int $actorId): ?string` | Since 0.27: gives the member the part in the piece, replacing their part in it, or removes it (`$partId` null). The rules of the assignments tab apply (status open or in preparation, piece in the program, part of that piece, accepted participation). Returns an error message or null. The caller checks `orchestra_projects_assignment_manage`. orchestra-seating uses it for the voices in the seating editor. |
| `partChanges(Project $project): string` | Since 0.35: the notice of the assignments tab about parts changed since the members were last informed, with "Inform members about changes" and "Don't inform members", or the review of the changes when the page asks for it. Its forms return to the current page. Empty unless the project is in Parts assigned and has changes, orchestra-email 0.10 is running and the current user has `orchestra_projects_assignment_manage` (the only method that checks permissions). orchestra-seating shows it on the seating tab. |
| `static reviewingPartChanges(): bool` | Since 0.35: whether the page asks for the review (`&orchestra_changes=review`); show nothing but `partChanges()` then, unless it is empty |

`Project` has `id`, `title`, `description`, `status` (`ProjectStatus`), `startDate` and `endDate`
(Y-m-d or null), and `concertDates` (list of Y-m-d in chronological order, empty if the project has
no concert; until 0.11 a single `concertDate`). Deleted users are left out of the user ID lists; their
`Assignment::$userId` is null.

## Email groups

`Email\ProjectEmailGroups::participantsGroupId()` returns the IDs of the email groups, e.g. for
`EmailService::composeUrl()`. The groups per piece and part were removed in 0.13.

## Actions

| Action | Arguments | Fired |
|--------|-----------|-------|
| `orchestra_projects_status_changed` | `int $projectId, string $newStatus, string $previousStatus` | After the status of a project changed |

## Tabs of other plugins

Since 0.14, other plugins add tabs to the project page, on the projects page and in wp-admin, with
two filters (`ProjectsService::TABS_FILTER` and `ProjectsService::TAB_CONTENT_FILTER`):

| Filter | Arguments | Returns |
|--------|-----------|---------|
| `orchestra_projects_tabs` | `array<string, string> $tabs, Project $project` | the tabs, key => label; add yours if the current user may see it |
| `orchestra_projects_tab_content` | `string $html, string $tab, Project $project, string $tabUrl` | the escaped HTML of your tab, `$html` for other tabs |

Built-in tabs cannot be replaced. A tab that is not in the filtered list cannot be opened, so check
the capabilities in `orchestra_projects_tabs`. Forms post to `admin-post.php` and return to
`$tabUrl`. orchestra-seating uses this for its "Seating" tab.

```php
add_filter(ProjectsService::TABS_FILTER, function (array $tabs, Project $project): array {
    if (current_user_can('orchestra_seating_plan_read')) {
        $tabs['seating'] = __('Seating', 'orchestra-seating');
    }
    return $tabs;
}, 10, 2);
```

## Automatic emails

With orchestra-email 0.10, orchestra-projects registers the automatic emails
`Email\ChangedPartsEmail::ID` (`orchestra_projects_changed_parts`), see
[Informing members about changed parts](architecture.md#informing-members-about-changed-parts),
and since 0.37 `Email\StatusEmails::OPEN_ID` (`orchestra_projects_open`) and
`Email\StatusEmails::PARTS_ASSIGNED_ID` (`orchestra_projects_parts_assigned`), see
[Informing members about status changes](architecture.md#informing-members-about-status-changes).

## Hooks used

orchestra-projects uses the filters `orchestra_library_piece_delete_blockers` and
`orchestra_library_part_delete_blockers`, and the action `orchestra_members_member_activated`.
