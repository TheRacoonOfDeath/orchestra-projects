# Architecture

orchestra-projects owns projects, programs, participation and assignments. It uses
orchestra-library for pieces, parts and sheet music and orchestra-members for members and their
active status, only through their public services.

## Storage

| Data | Storage |
|------|---------|
| Project: name, description | custom post type `orchestra_project` (title, content) |
| Project status and dates | post meta `orchestra_projects_status`, `_start_date`, `_end_date`, `_concert_date` (Y-m-d) |
| Conductors and organizers of a project | table `orchestra_projects_staff` |
| Program | table `orchestra_projects_program` (project, piece, position) |
| Participation | table `orchestra_projects_participation` (project, user, status, responded at, changed by) |
| Assignments | table `orchestra_projects_assignments` (project, piece, part, user) |

- The project status replaces the WordPress post status: saved projects are always published.
- Assignments store the piece of their part. Parts never move between pieces; the column makes
  "one part per piece and member" a unique key and the history queries simple.
- When a user is deleted, the user ID in participation and assignments becomes NULL. The history
  stays complete and shows "Former member". Staff entries of the user are deleted.
- Deleting a project (administrators only) deletes its rows in all tables.

## Rules

| Status | Visible to members | On the member page | Members respond | Assignments | Program |
|--------|:--:|:--:|:--:|:--:|:--:|
| Draft | | | | | ✓ |
| Open for Participation | ✓ | ✓ | ✓ | ✓ | ✓ |
| Preparation | ✓ | ✓ | | ✓ | ✓ |
| Completed | ✓ | ✓ | | | |
| Archived | ✓ (by link) | | | | |

- Projects move one step forward or back, e.g. back to Open for late responses.
- Opening a project creates Open participation records for all active users with the role
  member or organizer who have none yet. New accounts, reactivated members and users who get one
  of these roles while a project is open receive one as well.
- Members respond once; the decision is final. Organizers change any decision at any time. Leaving
  Accepted removes the member's assignments in the project, after a confirmation.
- Assignments need an accepted participation and a part of a piece in the program. A member plays
  at most one part per piece; assigning another part of the same piece moves them, after a
  confirmation.
- Removing a piece from the program removes its assignments, after a confirmation.
- Archived and unpublished pieces cannot be added to programs.
- orchestra-library refuses to delete pieces in a program and parts with assignments
  (`orchestra_library_piece_delete_blockers`, `orchestra_library_part_delete_blockers`).

## Assignment history

Not stored, derived from the assignments when a conductor assigns a part. Projects are ordered by
their concert date, or end or start date if there is none. Drafts and projects without dates are
ignored.

- **played last time**: assigned to the part in the most recent earlier project whose program
  contained the piece
- **played before**: assigned to the part in any earlier project
- **never played**

The marks are shown to users with `orchestra_projects_assignment_history_read` (conductors).

## Screens

Everything happens on the projects page `[orchestra_projects]`; administrators can use the same
views in **Projects** in wp-admin (views in `src/Ui`, shared by both).

| View | Who | Content |
|------|-----|---------|
| Project list | everyone | members: current projects with response, parts and download; staff: all projects; organizers: "New project" |
| Project → Overview | everyone who may see the project | details, staff, description, own response and parts, program; email buttons and status changes for staff |
| Project → Program | conductors, organizers | add, order and remove pieces |
| Project → Participation | conductors (read), organizers | accepted, pending, declined; organizers change decisions |
| Project → Assignments | conductors, organizers (read) | parts with assigned members; conductors assign |
| Project form | organizers | name, description, dates, staff (in wp-admin: the WordPress editor with the same fields) |

URLs on the projects page: `?orchestra_project=ID&orchestra_tab=program`, `&orchestra_edit=1`,
`?orchestra_project=new`. All forms post to `admin-post.php` with a nonce per project (or per
participation for responses), check the capability of the operation and return to the page they
were sent from.

## Email groups

With orchestra-core 0.3 or later, orchestra-projects registers groups for every project that is
open, in preparation or completed, listed under the project's name. Emailing them requires
`orchestra_projects_email_send`, checked with the project ID.

| Group ID | Recipients |
|----------|------------|
| `orchestra_projects_{project}_all` | everyone asked to respond |
| `orchestra_projects_{project}_accepted` / `_open` / `_declined` | by participation status |
| `orchestra_projects_{project}_piece_{piece}` | everyone assigned to a part of the piece |
| `orchestra_projects_{project}_part_{part}` | everyone assigned to the part |

Piece and part groups exist only when they have assignments. orchestra-members removes inactive
members from all groups. The Overview tab links to emails to the accepted, pending and all
participants, the Assignments tab to the players of each piece.

## Capabilities

See the catalogue in orchestra-core's `docs/capabilities.md`. All are implemented.
