# Doki Changelog

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
