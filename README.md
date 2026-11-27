# orchestra-projects

Projects, programs, participation and assignments for the Orchestra plugin suite.

Part of the Orchestra plugin suite. Requires [orchestra-core](https://github.com/TheRacoonOfDeath/orchestra-core),
[orchestra-library](https://github.com/TheRacoonOfDeath/orchestra-library) and
[orchestra-members](https://github.com/TheRacoonOfDeath/orchestra-members).

## Features

- Projects with dates, conductors and organizers, moving through Draft → Open for Participation →
  Preparation → Parts assigned → Completed; the project list has tabs for the active and the
  completed projects
- A registration deadline per project, shown on the card and the project page while the project is
  open for participation, in red for members who have not responded yet; seven days before it, the members who have not responded yet
  get a reminder email, and on the day of the deadline a second one starting with a red "Last
  chance!" (with orchestra-email 0.10 or later; organizers change both texts on the Email page)
- Project fields: questions to the participants (text, long text, checkbox or choice list, optionally
  required), defined centrally by the organizers (**Project fields** on the projects page, or
  **Projects → Project Fields**) and chosen per project. Members who accepted answer them while the
  project is open for participation, in "Questions" on the card (closed until opened) and on the
  project page; organizers see the answers on the Participation tab and in the CSV export
- An ordered program of pieces from the library, with the duration of each piece and the total
- Participation: opening a project asks every active member and organizer to accept or decline;
  the decision is final, organizers can change it. On the Participation tab, the accepted, pending
  and declined members are groups that open with a click
- Organizers export the accepted participants with their birth date, their age at the start of the
  project (orchestra-members 0.22 or later), their profile fields and their answers to the project
  fields as a CSV file for Excel; below them, every choice list is counted (e.g. "vegetarian
  5, vegan 3")
- Assignments of members to parts, with hints who played the part last time or in a completed
  project, and a button that gives everyone the part they played last time; the assignments can be
  downloaded as a PDF (with orchestra-core 0.8 or later)
- Members see their parts with the sheet music downloads on the project page and on the cards of
  the project list, where "Your parts" opens with a click
- Parts a member plays for the first time are labelled "new", for the member and on the
  assignments; the PDF marks them with an asterisk and a legend
- The assignments by piece or by member (a table of members and pieces), on the tab and in the PDF; on the tab, conductors and organizers see the pieces closed and open the one they work on
- Parts changed after the parts were assigned are remembered: conductors and organizers inform the
  affected members by email, each with only their own changes, or dismiss the changes (with
  orchestra-email 0.10 or later; organizers change the text on the Email page)
- Moving a draft to Open for Participation or a project to Parts assigned can inform the members
  by email: all active members (with the description of the project), or the accepted participants
  (with orchestra-email 0.10 or later; organizers change the texts on the Email page)
- In the project form, the end date takes the start date while it is empty, so its date picker opens
  in the month of the project
- `[orchestra_projects]`: members see their projects, respond, see their parts and download the
  sheet music of each part, or all of it in one zip; organizers and conductors create projects, change their status and manage
  program, participation and assignments on the same page (or in **Projects** in wp-admin)
- Email groups for [orchestra-email](https://github.com/TheRacoonOfDeath/orchestra-email), if it is
  installed: the accepted and pending participants of each project
- "Delete project" at the bottom of the project form: organizers delete projects that were never opened
  for participation, administrators every project (permanently, after a confirmation)
- Pieces and parts used in projects cannot be deleted in the library
- Privacy (GDPR): export and erasure with the WordPress privacy tools (erasing deletes comments,
  notes, answers to project fields and staff entries and keeps decisions and parts as history; deleting an account anonymizes
  it), a suggested privacy policy text, and the inactive status only shown to conductors and
  organizers
- An API for other plugins

## Installation

1. Install and activate orchestra-core 0.4, orchestra-library 0.13 (for the durations in the program) and orchestra-members 0.3 or later.
2. Upload `orchestra-projects-{VERSION}.zip` under **Plugins → Add New → Upload Plugin** and activate it.
3. Create the Orchestra pages (**Create Orchestra pages** in wp-admin), or put `[orchestra_projects]` on a page.

Updates are installed from the GitHub releases of this repository. The plugin reads
`update.json` from the latest release, so the GitHub API and its rate limit are not involved and
no token is needed.

## Documentation

- [Architecture](docs/architecture.md)
- [API for other plugins](docs/api.md)

The domain model, use cases and capabilities of the whole suite are documented in orchestra-core.

## Development

```bash
composer install
composer check   # coding standard, static analysis and unit tests
composer e2e     # end-to-end tests in a temporary WordPress (see tests/e2e/run.sh)
```

The end-to-end tests install WordPress with SQLite, the latest releases of the other Orchestra
plugins and a fresh build of this plugin, run them on PHP's built-in web server and test them over
HTTP as each role. They need PHP with `pdo_sqlite`, Python 3, curl, unzip and network access. Set
`GITHUB_TOKEN` if the GitHub API rate limit is reached.

## Releasing

1. Run `composer check` and `composer e2e`.
2. Bump `Version:` in `orchestra-projects.php`.
3. Commit and push, then push an annotated tag with short user-facing notes:
   `git tag -a v{VERSION} -m "notes" && git push origin v{VERSION}`. GitHub Actions runs
   `composer check`, builds the zip and `update.json` with `./build-plugin.sh`, signs their build
   provenance and creates the release
   ([orchestra-workflows](https://github.com/TheRacoonOfDeath/orchestra-workflows#releases)).
   `./build-plugin.sh` stays available for local test builds.
