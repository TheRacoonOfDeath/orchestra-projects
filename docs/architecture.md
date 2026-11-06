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
| Participation | table `orchestra_projects_participation` (project, user, status, responded at, changed by and at, member's comment, organizers' note; schema version 4) |
| Assignments | table `orchestra_projects_assignments` (project, piece, part, user) |
| What members were last informed about (Parts assigned only) | table `orchestra_projects_informed` (project, piece, part, user; schema version 5) and post meta `orchestra_projects_informed` |

- The project status replaces the WordPress post status: saved projects are always published.
- Schema version 4 drops the column `fee_paid` and the post meta `orchestra_projects_fee` of the
  project fees of 0.23 and 0.24, which were removed again.
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
| Parts assigned | ✓ | Active | | ✓ | ✓ | ✓ |
| Completed | ✓ | Completed | | | | |

- The project list has two tabs, **Active** and **Completed** (`?orchestra_completed=1`).
- Versions before 0.8 had an Archived status after Completed; such projects count as completed.

- Projects move one step forward or back, e.g. back to Open for late responses.
- **Parts assigned** (since 0.28, German "Stimmverteilung abgeschlossen", stored as
  `parts_assigned`) comes between Preparation and Completed. The project is still running: it is
  listed as active, keeps its email groups, and assignments and program can still be changed.
  "Complete the project" for projects that are over moves through it.
- **Members see the assignments only from Parts assigned on**
  (`ProjectStatus::assignmentsVisibleToMembers()`): before, the assignments tab and "Your parts"
  on the project list and overview only say "The parts have not been assigned yet." ("Stimmverteilung
  noch nicht abgeschlossen"), without parts or sheet music downloads. Conductors and organizers
  (`orchestra_projects_assignment_manage`) always see them, also organizers who play.
  orchestra-seating hides the seating from members in the same phases.
- Opening a project creates Open participation records for all active users with the role
  member, organizer or librarian (since 0.36; a member who also maintains the library) who have
  none yet. New accounts, reactivated members and users who get one of these roles while a project
  is open receive one as well. Librarians have the project capabilities of members.
- Members respond once; the decision is final. Organizers change any decision at any time, also
  for several people at once (checkboxes per group, `op=participation_bulk`; each change follows the
  same rules). Leaving Accepted removes the member's assignments in the project, after a
  confirmation.
- Members add an optional comment when responding (on the project page; the cards of the project
  list only have the buttons) and change it while the project is open for participation
  (`response=comment`, at most 500 characters, plain text). Organizers write a note per
  participation on the Participation tab (`op=participation_note`, at most 1000 characters). Both
  need `orchestra_projects_participation_manage` to be seen, which only organizers have; the member
  sees their own comment, never the note. Both are in the CSV export and in the personal data
  export (the note too, because it is data about the member), and both are cleared when the
  account is deleted.
- Assignments need an accepted participation and a part of a piece in the program. A member plays
  at most one part per piece. The assignments tab offers only accepted members without a part in
  the piece; to move a member, remove their assignment first. The handler still moves a member
  when the request asks for it (`move=1`) and refuses otherwise.
- While the assignments can be changed, a piece in which an accepted member has no part yet shows
  a warning badge next to its title.
- **Take over the assignment** ("Verteilung übernehmen", `op=copy_assignments`, since 0.29):
  below the title of every piece, conductors and organizers choose another piece of the program.
  After a confirmation, all assignments of the piece are removed, and every accepted member gets
  the part whose name is exactly the name of their part in the chosen piece; members whose part
  has no namesake are left without a part.
- Removing a piece from the program removes its assignments, after a confirmation.
- Archived and unpublished pieces cannot be added to programs.
- orchestra-library refuses to delete pieces in a program and parts with assignments
  (`orchestra_library_piece_delete_blockers`, `orchestra_library_part_delete_blockers`).

## Informing members about changed parts

Since 0.33, with orchestra-email 0.10. Members see their parts from Parts assigned on, so changes
made after that are communicated:

- When a project enters Parts assigned, a snapshot of its assignments is stored
  (`Assignment\AssignmentChangeService`, table `orchestra_projects_informed`). The changes are the
  difference between the snapshot and the current assignments per member and piece
  (`AssignmentChanges::between()`): a new part, another part, or no part any more. Every way of
  changing parts counts, also the seating of orchestra-seating, participations changed away from
  accepted and pieces removed from the program; a part changed back is no change. Projects that
  were in Parts assigned before 0.33 get their snapshot the first time it is needed.
- On the assignments tab, conductors and organizers (`orchestra_projects_assignment_manage`) see
  "The parts of N members changed since the members were last informed." with two buttons:
  - **Inform members about changes** (`&orchestra_changes=review`) lists every affected member
    with their changes and whether they get an email (not if inactive, without an address, or
    automatic emails turned off in the profile). **Send** (`op=changes_inform`, after a
    confirmation) sends the automatic email `orchestra_projects_changed_parts` of orchestra-email:
    every member gets only their own changes, as a list per piece in program order, with "new"
    for parts they never played, and a link to the assignments tab. The request carries a
    fingerprint of the reviewed changes; if the parts changed in the meantime, nothing is sent and
    the review has to be opened again.
  - **Don't inform members** (`op=changes_forget`, after a confirmation that explains it) keeps
    the parts and forgets the changes.
  Both take a new snapshot.
- The seating tab of orchestra-seating shows the same notice and review above the seating
  (`ProjectsService::partChanges()`, since 0.35), because voices changed there are part changes
  too. Only part changes count; seats alone are no change.
- Leaving Parts assigned (back to Preparation, on to Completed, also with "Complete the project")
  forgets the changes; the confirmation of the status change says how many members were not
  informed.
- The text of the email (`Email\ChangedPartsEmail`) has a default with the placeholders `{name}`,
  `{project}`, `{changes}` and `{link}`. Organizers (`orchestra_projects_notification_edit`)
  change it on the Email page under "Automatic emails". Replies go to the user who sent it.
- Without orchestra-email 0.10, the snapshot is still kept, but nothing is shown.

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
Since 0.30, the members in the form of a part are also colored: green for the part they played
last time, yellow for a part they played before that in any other project (also a running one),
no color if they never played it (`assets/orchestra-projects.css`, `orchestra-projects-played--last`
and `--earlier`). The members are listed green first, then yellow, then the others, each group by
name (since 0.31); the marks in words ("played last time", …) are only read out by screen readers. orchestra-seating colors the parts in its voice dropdowns the same way
(`ProjectsService::playedParts()`).

**New parts:** an assignment to a part the member was never assigned to in another project
(`AssignmentHistory::newParts()`) is labelled "new", as a yellow starburst (`Ui\Badges::newPart()`;
`assets/orchestra-projects.css`, the plugin's only stylesheet, is enqueued by the label and the
project list, so it is loaded only where they are shown; it also gives the project cards more room): on the member's own parts (project list and
overview), next to the player on the assignments tab, and with an asterisk after the name in the
PDF, which then ends with a legend. A member's first project makes every part new.

## Screens

Everything happens on the projects page `[orchestra_projects]`; administrators can use the same
views in **Projects** in wp-admin (views in `src/Ui`, shared by both).

| View | Who | Content |
|------|-----|---------|
| Project list | everyone | a card per project: the status above the title, the dates (the concerts only on the project page); members who take part: response, and every piece of the program with its composer, below it their part with "new" and the download, or "No part assigned yet"; staff: all projects; organizers: "New project". The whole card opens the project (`orchestra-card--linked` of orchestra-core 0.15; its buttons and downloads stay clickable); the cards fill the width, up to three per row, and are as high as their content |
| Project → Overview | everyone who may see the project | details, staff, description, own response and parts; email buttons and status changes for staff |
| Project → Who accepted | members | the names of everyone who accepted (conductors and organizers see them on the Participation tab) |
| Project → Program | everyone who may see the project | members: the ordered list of pieces; conductors and organizers: add, order and remove pieces |
| Project → Participation | conductors (read), organizers | accepted, pending, declined, with the last change and who entered it; organizers see the members' comments, write notes, change decisions and export the accepted participants. The number of a member's parts is shown as "3 parts". On phones the rows are compact (orchestra-core 0.15): name, then the last change and the parts on one line, then "Edit" with the comment, the note and the change |
| Project → Assignments | everyone who may see the project | "By piece": parts with assigned members; conductors and organizers assign there, members only see it (no forms, history marks, warnings or PDF); "By member" (`&orchestra_assignments_view=members`): a read-only table with a row per member and a column per piece (`AssignmentMatrix`); in Parts assigned, the changed parts to inform the members about (see above) |
| Project form | organizers | name, description, dates, staff (in wp-admin: the WordPress editor with the same fields) |

URLs on the projects page: `?orchestra_project=ID&orchestra_tab=program`, `&orchestra_edit=1`,
`?orchestra_project=new`. All forms post to `admin-post.php` with a nonce per project (or per
participation for responses), check the capability of the operation and return to the page they
were sent from.

On the assignments tab, a member is removed from a part with a small "×" after their name (red on
hover, read out as "Remove <name>").

On "By piece", every piece can be closed and opened (`<details>`, no JavaScript needed). Its
summary line is the title, "N of M members assigned" and, for those who assign, the warning badge.
Conductors and organizers (`orchestra_projects_assignment_manage`) see all pieces closed, because
a piece with many players is long; members see them open. The forms inside a piece (assign, ×,
take over) return to `&orchestra_piece=<id>#orchestra-piece-<id>`, which opens that piece and
scrolls to it, so it stays open after each change.

Dates never break inside a date (`Dates::unbreakable()`), only
between them.

## Assignments as PDF

The assignments tab has a "Download PDF" button
(`admin-post.php?action=orchestra_projects_assignments_pdf&project=ID`) for conductors and
organizers (`orchestra_projects_assignment_manage`); members see the tab, not the PDF. The PDF lists every piece of the program with its composer, its parts and the assigned
players, and the project's dates and concerts; then, on a page of its own, the parts by member as in
the "By member" view. Players of a new part (see above) have an asterisk
after their name, explained below the last piece. It is created by orchestra-core 0.8
(`Orchestra\Core\Pdf\Pdf`); with an older core the button is hidden.

## Participants export

Organizers download the accepted participants of a project as a CSV file from the Participation
tab (`admin-post.php?action=orchestra_projects_export_participants&project=ID`). It has one column
each for last name, first name and email address, then one per profile field of orchestra-members
in their order; checkboxes are exported as "Yes" or "No"; then the member's comment and the
organizers' note. Users without first and last name get their display name as last name. Rows are
ordered by last and first name.

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
open or in preparation, listed under the project's name, the next project (by start date) first.
Projects that are over but not completed yet keep their groups. Emailing them requires
`orchestra_projects_email_send`, checked with the project ID.

| Group ID | Recipients |
|----------|------------|
| `orchestra_projects_{project}_all` | accepted and pending participants (the ID is kept from when the group included everyone asked) |
| `orchestra_projects_{project}_accepted` / `_open` | by participation status |

Members who declined are in no group, and there are no groups per piece or part. orchestra-members
removes inactive members from all groups. The Overview tab links to emails to the accepted, the
pending, and the accepted and pending participants.

## Capabilities

See the catalogue in orchestra-core's `docs/capabilities.md`. All are implemented.
