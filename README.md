# orchestra-projects

Projects, programs, participation and assignments for the Orchestra plugin suite.

Part of the Orchestra plugin suite. Requires [orchestra-core](https://github.com/TheRacoonOfDeath/orchestra-core),
[orchestra-library](https://github.com/TheRacoonOfDeath/orchestra-library) and
[orchestra-members](https://github.com/TheRacoonOfDeath/orchestra-members).

## Features

- Projects with dates, conductors and organizers, moving through Draft → Open for Participation →
  Preparation → Completed; the project list has tabs for the active and the completed projects
- An ordered program of pieces from the library
- Participation: opening a project asks every active member and organizer to accept or decline;
  the decision is final, organizers can change it
- Assignments of members to parts, with hints who played the part last time or in a completed
  project, and a button that gives everyone the part they played last time
- `[orchestra_projects]`: members see their projects, respond, see their parts and download the
  sheet music of each part, or all of it in one zip; organizers and conductors create projects, change their status and manage
  program, participation and assignments on the same page (or in **Projects** in wp-admin)
- Email groups for the email service of orchestra-core (0.3 or later): participants by status,
  and the players of each piece and part
- Pieces and parts used in projects cannot be deleted in the library
- Export of participation and assignments with the WordPress privacy tools; deleting an account
  anonymizes its history
- An API for other plugins

## Installation

1. Install and activate orchestra-core 0.4, orchestra-library 0.2 and orchestra-members 0.3 or later.
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
3. Run `./build-plugin.sh`; it creates `build/orchestra-projects-{VERSION}.zip` with production
   dependencies only, and `build/update.json`.
4. Create a GitHub release `v{VERSION}` and attach the zip **and `update.json`**.
