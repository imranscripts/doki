# Doki
### *Create Apps and Workflows by asking AI! and do a lot more...*

<img width="2988" height="1496" alt="doki-app-mkt_2026-04-12_13-37-19_c79" src="https://github.com/user-attachments/assets/a39b7c83-a5bb-4cd0-817a-934fad678faa" />

Doki is a local-first workspace for running operational workflows, managing Docker-backed tools, and composing internal utilities in one place.

It combines:

- a web UI for commands, templates, targets, secrets, users, and audit history
- an app system with built-in apps plus runtime-installed apps
- App Studio for building new apps inside Doki
- an optional stealth login flow for hiding the normal login page
- a local Docker target and Go orchestrator for app execution

## What Doki does

Out of the box, Doki gives you:

- `Apps` for browsing and launching installed tools
- `Workflows` for reusable commands, templates, and run history
- `App Studio` for authoring and previewing apps
- AI controls for administration and App Studio
- `Configuration` for targets, environments, and encrypted secrets
- `Administration` for users, groups, module visibility, audit logs, and source management

Built-in apps currently live in [`app/apps`](app/apps):

- `playwright runner`
- `image-compress`
- `text-diff`

Runtime-installed app code lives in [`app/data/installed-apps`](app/data/installed-apps), and per-app runtime state lives in [`app/data/apps`](app/data/apps).

## Quick Start

### Requirements

You need:

- Docker with `docker compose`

### First run

From the repo root:

```bash
./setup.sh
./start.sh
```

Then open:

```text
http://localhost:8099
```

On first setup, Doki will:

- create runtime folders and keys
- seed the built-in `local` Docker target
- print a stealth key
- send you into the onboarding flow

During onboarding, you:

1. create the first `super-admin`
2. choose whether stealth login stays enabled
3. choose which top-level modules are visible

If stealth stays enabled, the normal login page is hidden. You will first see a fake not-found page, and you type the stealth key there to unlock login.

## Common Commands

Bootstrap and health:

```bash
./setup.sh
./setup.sh --check
./setup.sh --verbose
```

Runtime control:

```bash
./start.sh
./stop.sh
```

Fresh-install reset:

```bash
./reset.sh --dry-run
./reset.sh --force
```

`reset.sh` removes runtime state, generated keys, local databases, sessions, installed app code, app runtime data, Playwright output, and other generated files, while keeping bundled app code in [`app/apps`](app/apps).

## How First Run Works

- [`setup.sh`](setup.sh) runs the CLI bootstrap in [`app/scripts/setup.php`](app/scripts/setup.php)
- setup prepares runtime state and verifies the local Docker target through [`app/includes/OnboardingManager.php`](app/includes/OnboardingManager.php)
- [`start.sh`](start.sh) and [`stop.sh`](stop.sh) control the Doki stack through [`app/scripts/runtime-control.php`](app/scripts/runtime-control.php)
- the web app routes fresh installs to onboarding from [`app/index.php`](app/index.php)

## Repo Layout

- [`app`](app): main PHP application
- [`app/apps`](app/apps): bundled app code
- [`app/data`](app/data): runtime state, SQLite DB, sessions, keys, installed apps, and app data
- [`templates`](templates): command template definitions
- [`orchestrator-go`](orchestrator-go): Go control-plane/orchestration service
- [`docker-compose.yml`](docker-compose.yml): local stack definition
- [`Dockerfile`](Dockerfile): PHP/Apache runtime image

## Security Notes

Doki is currently designed for a trusted local or self-hosted environment.

The local stack currently uses:

- Docker socket access
- privileged containers
- a read-only host root mount inside the main app container

That is powerful and convenient for local orchestration, but it also means you should treat a Doki install as highly trusted infrastructure, not as a locked-down multi-tenant service.

## Development Notes

- The app listens on `http://localhost:8099`
- The orchestrator host port is auto-selected and persisted, defaulting to `8098` when available
- The host-side setup flow uses your local PHP CLI, so missing PHP extensions on the host will block `./setup.sh`

## Status

Doki is under active development. The core onboarding, runtime control, local target seeding, module visibility controls, and recovery flow are in place, but the project is still evolving quickly.

## License

Doki is available under the [MIT License](LICENSE).

## Contributing

Contributions are welcome. If you want to improve Doki, fix a bug, tighten the UX, or add a useful app or workflow, pull requests are appreciated.
