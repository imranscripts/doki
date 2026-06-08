# Doki Changelog

## 1.2.6 - 2026-06-08

Makes the Doki Updates page fetch stable releases automatically.

### Changed

- The Updates page now checks for the latest stable tag when it opens.
- The Updates page rechecks stable tags every five minutes while open.
- The health/state refresh remains local and lightweight.
- The target version dropdown now follows the checked latest release unless the admin manually selects another version.

### Database

- Global Doki schema remains at version 17.
- No destructive database migration is declared.

### Destructive Changes

- None declared.

## 1.2.5 - 2026-06-08

Patch release for testing the fixed Doki update restart flow.

### Changed

- Bumped Doki to `1.2.5` so installations on `1.2.4` can perform a real stable-tag update.
- Kept the updater, restart, and migration behavior unchanged from `1.2.4`.

### Database

- Global Doki schema remains at version 17.
- No destructive database migration is declared.

### Destructive Changes

- None declared.

## 1.2.4 - 2026-06-08

Fixes helper-initiated Compose restarts by preserving host repo paths and the active Compose project.

### Fixed

- Added `DOKI_HOST_ROOT` so update-helper restarts use host filesystem paths for Compose bind mounts.
- Passed `DOKI_HOST_ROOT` and the active Compose project into helper-initiated `docker compose stop` and `docker compose up` commands.
- Added an explicit default Compose project name so helper-side Compose calls do not inherit the `/workspace` directory name.
- Taught the update-helper to discover the active Compose project from running Doki container labels.
- Prevented updated services from being recreated with container-local `/workspace` bind paths.
- Prevented helper restarts from creating a separate `workspace` Compose project with conflicting container names.
- Fixed failed-restart recovery so the helper still attempts to bring core services back up after a restart error.

### Database

- Global Doki schema remains at version 17.
- No destructive database migration is declared.

### Destructive Changes

- None declared.

## 1.2.3 - 2026-06-08

Makes update-helper Git safety self-configuring at container startup.

### Fixed

- Added an update-helper container entrypoint that marks the mounted Doki checkout as a safe Git directory inside the container.
- Kept per-command `safe.directory` handling as a second layer of protection.
- Normal installs no longer require any manual Git config command before checking for updates.

### Database

- Global Doki schema remains at version 17.
- No destructive database migration is declared.

### Destructive Changes

- None declared.

## 1.2.2 - 2026-06-08

Fixes updater Git safety handling for Docker-mounted workspaces.

### Fixed

- The update-helper now passes Git's `safe.directory` option for every Git command.
- This prevents Docker-mounted Doki checkouts from failing with Git dubious ownership errors at `/workspace`.

### Database

- Global Doki schema remains at version 17.
- No destructive database migration is declared.

### Destructive Changes

- None declared.

## 1.2.1 - 2026-06-08

Patch release for exercising the Doki stable updater flow.

### Changed

- Bumped Doki to `1.2.1` so installations on `1.2.0` can perform a real stable-tag update.
- Kept the database schema unchanged at version 17.

### Database

- Global Doki schema remains at version 17.
- No destructive database migration is declared.

### Destructive Changes

- None declared.

## 1.2.0 - 2026-06-08

Adds Doki's stable-tag update flow.

### Added

- Added a super-admin Doki Updates page.
- Added a localhost-only `update-helper` service for release checks, health polling, migration dry-runs, and update progress.
- Added short-lived updater access tokens minted from Doki for super-admins.
- Added stable semantic-version tag discovery for releases such as `v1.2.0`.
- Added a CLI migration probe for dry-run and apply stages.

### Changed

- Updates are blocked when managed files have local changes.
- Migration dry-runs run against a copied SQLite database before any real update is applied.
- Update apply creates a live database backup before changing code or running real migrations.
- Updater token/state files and SQLite WAL sidecars are ignored by Git.

### Database

- Global Doki schema remains at version 17.
- No destructive database migration is declared.

### Destructive Changes

- None declared.

## 1.1.0 - 2026-06-08

Current release after the `1.0.0` baseline.

### Added

- Added Logger as a core installed app for viewing configured log files across Doki targets.
- Added release metadata for Doki's future admin updater.

### Changed

- Setup now runs inside the `php-app` container so host PHP extensions no longer block setup.
- `start.sh` and `setup.sh` now check Docker/Compose availability and give clearer startup guidance.
- README setup order now starts Doki before running setup.

### Database

- Global Doki schema remains at version 17.
- Logger creates app-owned tables on first use.

### Destructive Changes

- None declared.

## 1.0.0 - 2026-04-12

Initial Doki release baseline from `origin/main` commit `a9a1b7c2d1b277bed8021132f80e04bdecf6d660`.

### Included

- Doki admin, app management, workflows, sources, onboarding, and SQLite schema version 17.

### Database

- Global Doki schema version 17.

### Destructive Changes

- None declared.
