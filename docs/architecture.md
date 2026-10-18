# Architecture

orchestra-projects owns projects, programs, participation and assignments. It uses
orchestra-library for pieces, parts and sheet music and orchestra-members for members and their
active status, only through their public services.

## Storage

| Data | Storage |
|------|---------|
| Project: name, description | custom post type `orchestra_project` (title, content) |
| Project status and dates | post meta `orchestra_projects_status`, `_start_date`, `_end_date` (Y-m-d); `_concert_date` once per concert |
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

| Status | Visible to members | Listed under | Members respond | Assignments | Program | Email groups |
|--------|:--:|:--:|:--:|:--:|:--:|:--:|
| Draft | | Active (staff only) | | | ✓ | |
| Open for Participation | ✓ | Active | ✓ | ✓ | ✓ | ✓ |
| Preparation | ✓ | Active | | ✓ | ✓ | ✓ |
| Completed | ✓ | Completed | | | | |

- The project list has two tabs, **Active** and **Completed** (`?orchestra_completed=1`).
- Versions before 0.8 had an Archived status after Completed; such projects count as completed.

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

Not stored, derived from the assignments of the piece in all other projects when a conductor
assigns a part. The order in which the assignments were made counts, not the project dates.

- **played last time**: the member's latest assignment of this piece, in any other project, was
  to this part
- **played in a completed project**: assigned to this part in a completed project
  (shown together with "played last time" when both apply)
- **never played**: never assigned to this part in another project

**Assign the parts played last time** on the assignments tab gives every accepted member who has
no part in a piece yet the part of their latest assignment of that piece. Members who never played
the piece stay unassigned; existing assignments are not changed.

The marks are shown to users with `orchestra_projects_assignment_history_read` (conductors and organizers).

## Screens

Everything happens on the projects page `[orchestra_projects]`; administrators can use the same
views in **Projects** in wp-admin (views in `src/Ui`, shared by both).

| View | Who | Content |
|------|-----|---------|
| Project list | everyone | members: current projects with response, parts and download; staff: all projects; organizers: "New project" |
| Project → Overview | everyone who may see the project | details, staff, description, own response and parts, program; email buttons and status changes for staff |
| Project → Program | conductors, organizers | add, order and remove pieces |
| Project → Participation | conductors (read), organizers | accepted, pending, declined; organizers change decisions and export the accepted participants |
| Project → Assignments | conductors, organizers | parts with assigned members; conductors and organizers assign |
| Project form | organizers | name, description, dates, staff (in wp-admin: the WordPress editor with the same fields) |

URLs on the projects page: `?orchestra_project=ID&orchestra_tab=program`, `&orchestra_edit=1`,
`?orchestra_project=new`. All forms post to `admin-post.php` with a nonce per project (or per
participation for responses), check the capability of the operation and return to the page they
were sent from.

## Participants export

Organizers download the accepted participants of a project as a CSV file from the Participation
tab (`admin-post.php?action=orchestra_projects_export_participants&project=ID`). It has one column
each for last name, first name and email address, then one per profile field of orchestra-members
in their order; checkboxes are exported as "Yes" or "No". Users without first and last name get
their display name as last name. Rows are ordered by last and first name.

- Requires `orchestra_projects_participation_export` and, for every participant,
  `orchestra_members_profile_read`, because the profiles are sensitive.
- The file opens in Excel by double-click: UTF-8 with a byte order mark, every cell quoted, Windows
  line endings, and the column separator Excel expects in the Orchestra language (`;` in German,
  `,` in English; translatable with the context "CSV column separator").
- Values starting with `=`, `+`, `-`, `@` or a tab are prefixed with `'`, so Excel never runs them
  as formulas.
- Needs orchestra-members 0.9 for names and profile fields; with an older version the button is
  hidden.

## Email groups

When orchestra-email is active, orchestra-projects registers groups for every project that is
open or in preparation, listed under the project's name. Emailing them requires
`orchestra_projects_email_send`, checked with the project ID.

| Group ID | Recipients |
|----------|------------|
| `orchestra_projects_{project}_all` | accepted and pending participants (the ID is kept from when the group included everyone asked) |
| `orchestra_projects_{project}_accepted` / `_open` | by participation status |
| `orchestra_projects_{project}_piece_{piece}` | everyone assigned to a part of the piece |
| `orchestra_projects_{project}_part_{part}` | everyone assigned to the part |

Members who declined are in no group: only accepted and pending participants are emailed (piece
and part groups contain assigned members, who have accepted). Piece and part groups exist only when
they have assignments. orchestra-members removes inactive members from all groups. The Overview tab
links to emails to the accepted, the pending, and the accepted and pending participants, the
Assignments tab to the players of each piece.

## Capabilities

See the catalogue in orchestra-core's `docs/capabilities.md`. All are implemented.
