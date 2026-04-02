# Doki App Studio Plan

## Summary

This document proposes `App Studio`, a first-class workflow for creating, previewing, testing, and publishing Doki apps from inside Doki.

The goal is to make this flow easy enough for non-experts while still giving technical users a real repo-backed development environment:

1. Create an app from a starter or connect a GitHub/GitLab repo.
2. Describe an app in chat and let Doki scaffold or edit it with AI.
3. Edit the app in a writable Doki workspace.
4. Preview the app live inside Doki.
5. Run smoke tests and Playwright tests.
6. Commit and push changes back to the repo.
7. Publish or update the installable app from the same workspace.

This plan is intentionally anchored to Doki's current architecture:

- App discovery/install/update: `app/includes/AppManager.php`
- Git providers and repositories: `app/includes/SourcesManager.php`
- Playwright project management: `app/includes/PlaywrightProjectManager.php`
- App trust and capabilities: `app/includes/AppCapabilities.php`
- App runtime/image lifecycle: `app/includes/AppImageBuilder.php`

## Why This Is Needed

Doki already has most of the platform pieces, but they currently behave like separate products:

- Apps can be discovered from Git sources and installed into `app/apps/`.
- Source repos are synced into cache under `app/data/sources/.cache/`.
- Playwright projects can be created, uploaded, synced, and run.
- Source-backed Playwright projects are intentionally read-only after sync.

That is good for safety and operations, but not good for app authoring.

Today the missing concept is a writable, repo-backed development workspace that Doki can treat as:

- a live app source
- a test source
- a git working tree
- a publishable package

## Product Goals

### Primary goals

- Let a user create a working Doki app without leaving the Doki UI.
- Make repo-backed development a first-class flow, not a cache/sync side effect.
- Keep preview, testing, git, and publishing in one place.
- Preserve Doki's existing trust model for installed apps.
- Default to safe behavior for untrusted or in-progress code.

### Non-goals for v1

- Full IDE replacement.
- Real-time collaborative editing.
- Arbitrary framework support beyond Doki apps and repo-linked Playwright tests.
- AI must not become a requirement for basic App Studio usage. Manual create, edit, preview, and publish flows should remain first-class.

## Guiding Principles

- Separate `development workspace` from `installed app`.
- Keep source of truth in Git when a workspace is repo-backed.
- Preserve the current manifest model in `manifest.yaml`.
- Reuse current runtime, source, and Playwright systems where possible.
- Make the simplest path the safest path.

## Proposed Product Surface

Add a new top-level module: `App Studio`.

### Core user journeys

#### 1. Create from starter

1. User clicks `Create App`.
2. User picks a starter:
   - Simple utility app
   - CRUD/data app
   - API wrapper app
   - Dashboard app
3. User fills:
   - app ID
   - app name
   - description
   - icon
   - color
4. Doki creates a writable workspace with a valid `manifest.yaml`, starter PHP pages, and optional starter tests.
5. Doki opens the workspace editor and preview.

#### 2. Connect an existing repo

1. User clicks `Import Repo`.
2. User chooses provider, repo, branch, and app path.
3. Doki creates a writable clone in a workspace.
4. Doki detects:
   - Doki app path
   - Playwright test path
   - manifest/runtime requirements
5. Doki opens preview, file editor, git panel, and test runner.

#### 3. Test and iterate

1. User edits files in the workspace.
2. User refreshes preview or Doki auto-refreshes.
3. User runs:
   - manifest validation
   - smoke checks
   - Playwright tests
4. Doki shows failures inline and stores recent runs.

#### 4. Create with AI

1. User opens `AI App Creation` inside App Studio.
2. User picks an AI provider:
   - local Ollama
   - OpenAI-compatible provider such as OpenAI, OpenRouter, or a self-hosted endpoint
3. User describes the app in plain language.
4. Doki sends workspace-aware context and uses workspace-scoped tools to:
   - generate starter files
   - edit existing files
   - update `manifest.yaml`
   - run validation or preview sync when requested
5. Doki shows a proposed diff or file changes before applying them.
6. User accepts the changes and continues editing normally.

#### 5. Push and publish

1. User reviews git status and diff.
2. User commits changes from Doki.
3. User pushes to branch or creates a PR/MR.
4. User optionally publishes the workspace app to Doki as an installed app or updates an existing installed app from the same repo.

## Key Architectural Change: Workspace Model

Introduce a new concept: `workspace`.

This is the missing layer between:

- source repo cache
- editable development files
- live app preview
- test execution
- git write operations

### Why a workspace is necessary

Current source handling is optimized for discovery and install/update:

- repo sync -> cache clone
- app install -> copy app into `app/apps/<id>`

That model is correct for published apps, but weak for authoring because:

- the cache is not a first-class editable worktree
- installed apps are the runtime copy, not the authoring source of truth
- repo-backed Playwright projects are read-only by design

The workspace model keeps those concerns separate:

- `source cache`: remote sync and discovery
- `workspace`: writable authoring state
- `installed app`: published app state available to users

## Proposed Filesystem Layout

Add a new workspace root:

```text
app/data/workspaces/
  <workspace-id>/
    repo/                # writable git working tree
    state/
      workspace.yaml     # metadata snapshot
      preview.json       # preview state
      tests.json         # recent test summary
      git.json           # cached git status summary
```

Optional generated assets:

```text
app/data/workspaces/<workspace-id>/artifacts/
app/data/workspaces/<workspace-id>/reports/
```

## Proposed Repo-Level Metadata

Add an optional repo config file:

```yaml
# doki.project.yaml
app:
  id: my-app
  path: apps/my-app

tests:
  playwrightPath: tests/playwright

preview:
  entry: apps/my-app/index.php

publish:
  sourceType: apps
```

This file is optional in v1.

Fallback behavior if it does not exist:

- detect `manifest.yaml` at repo root or `repo/apps/<app-id>/manifest.yaml`
- detect Playwright by `playwright.config.ts` or `playwright.config.js`

Important: this does not replace `manifest.yaml`. The app manifest remains the source of truth for the app itself. `doki.project.yaml` only describes how Doki should work with the repo as a development project.

## Data Model Changes

Add a new table: `app_workspaces`

Suggested fields:

- `id`
- `name`
- `description`
- `source_type` (`starter`, `repository`, `local`)
- `provider_id`
- `repository_id`
- `repo_url`
- `default_branch`
- `current_branch`
- `app_id`
- `app_path`
- `playwright_path`
- `workspace_root`
- `preview_mode`
- `preview_status`
- `linked_installed_app_id`
- `trust_mode`
- `last_test_run_at`
- `last_preview_at`
- `last_sync_at`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Optional follow-on table: `workspace_runs`

Suggested fields:

- `id`
- `workspace_id`
- `run_type` (`validate`, `smoke`, `playwright`, `publish`)
- `status`
- `summary`
- `log_path`
- `report_path`
- `created_by`
- `created_at`

## New Backend Components

### `WorkspaceManager.php`

New core service responsible for:

- create starter workspace
- clone repo into workspace
- open existing workspace
- resolve app path and test path
- persist workspace metadata
- hand off to preview, git, and test services

### `WorkspaceGitManager.php`

New git write service responsible for:

- status
- diff
- branch list
- checkout branch
- create branch
- commit
- pull
- push
- create PR/MR payload preparation

This should reuse provider credentials from `SourcesManager.php` rather than inventing a second credential system.

### `WorkspacePreviewManager.php`

New preview service responsible for:

- resolve the app inside a workspace
- validate manifest presence
- map preview requests to workspace files
- build or reuse custom runtime when needed
- show status for build, stale runtime, and preview errors

### `WorkspaceTemplateManager.php`

New starter/template service responsible for:

- creating starter app files
- applying variable substitution
- optionally generating starter tests

### `AIProviderManager.php`

New AI provider service responsible for:

- storing provider definitions and secrets
- testing connectivity
- listing models where supported
- normalizing chat requests across local and remote providers

Supported provider families should be:

- `ollama`
- `openai-compatible`

That lets Doki support:

- local Ollama on the host
- OpenAI API
- OpenRouter
- self-hosted OpenAI-compatible endpoints

Future providers can be added without changing App Studio chat UX.

### `WorkspaceAIManager.php`

New workspace-aware AI service responsible for:

- building the prompt and workspace context
- selecting the provider and model
- exposing a safe tool surface to the model
- producing proposed file changes
- applying accepted changes back into the workspace
- auditing AI actions

## Changes to Existing Components

### `app/includes/SourcesManager.php`

Extend it so Doki can:

- create a writable clone for a workspace, not only a cache clone
- reuse provider token handling for push operations
- list branches when creating/importing a workspace
- support repo metadata for workspace creation

Do not remove the existing cache-based sync flow. Installed app discovery still needs it.

### `app/includes/AppManager.php`

Add support for:

- previewing an app from a workspace path
- publishing from a workspace to `app/apps/<app-id>`
- linking an installed app to a workspace
- updating an installed app directly from a linked workspace

Important boundary:

- `install/update from source` remains the published-app workflow
- `preview/publish from workspace` becomes the authoring workflow

### `app/includes/PlaywrightProjectManager.php`

Extend it so Doki can treat a workspace as a first-class test source:

- create a temporary or managed Playwright project view over `workspace/repo/<path>`
- allow editable workspace-linked test sources
- avoid the current read-only restriction for workspace-owned test trees
- keep the current read-only behavior for repository-managed synced projects

### `app/includes/AppImageBuilder.php`

Support workspace previews for apps with runtime requirements:

- compute runtime from workspace manifest
- build a preview image keyed by workspace ID plus manifest/runtime hash
- avoid colliding with installed-app runtime state

Suggested image/container naming:

- `doki-ws-app-<workspace-id>`
- `doki-ws-fpm-<workspace-id>`

## Preview Model

The preview experience should feel immediate, but the implementation can stay simple.

### v1 preview approach

- Route preview requests through a new endpoint, for example:
  - `/studio/preview/<workspace-id>/`
- Resolve app files from `app/data/workspaces/<workspace-id>/repo/<app-path>/`
- Reuse the existing app bootstrap logic wherever possible
- If the manifest declares `runtime.phpExtensions`, build or reuse a workspace-specific FPM image

### Preview constraints

- Preview apps are private to the editor by default
- Preview apps are not auto-added to normal app navigation
- Preview apps should be marked clearly as `workspace preview`
- Preview state should never implicitly replace the installed app

## Testing Model

Testing should be integrated into the same workspace.

### Validation tiers

#### Tier 1: instant checks

- manifest exists
- manifest parses
- required manifest fields exist
- declared navigation pages exist
- runtime declarations are well formed
- capability declarations are valid

#### Tier 2: smoke checks

- preview route loads
- primary navigation page returns success
- app bootstrap does not fatal

#### Tier 3: Playwright

- run workspace-linked Playwright tests
- store reports under workspace artifacts or existing Playwright report storage
- show latest status in App Studio

### Testing UX

Every workspace should have:

- `Validate`
- `Preview`
- `Run smoke tests`
- `Run Playwright`

## Git Authoring Flow

This is the largest capability gap between current Doki behavior and the desired authoring workflow.

### v1 git actions

- show current branch
- show changed files
- show staged/unstaged summary
- stage all or selected files
- commit with message
- push branch
- pull branch
- create branch from current branch

### v1.1 git actions

- open PR for GitHub
- open MR for GitLab
- show ahead/behind
- compare with remote default branch

### Credential model

Use provider credentials already stored via `SourcesManager.php` and `ConfigManager.php`.

Do not expose raw tokens to the browser.

Git write operations should stay server-side and audited.

### Audit events

Add events for:

- workspace.create
- workspace.clone
- workspace.preview
- workspace.validate
- workspace.test
- workspace.commit
- workspace.push
- workspace.publish

## Publish Model

Publishing must be explicit.

### Publish flow

1. Validate workspace app.
2. Review trust implications from `manifest.yaml`.
3. Review runtime changes.
4. Confirm target app ID.
5. Copy or sync from workspace app path into `app/apps/<app-id>`.
6. Update installed-app metadata.
7. Trigger runtime rebuild if needed.

### Rules

- Publishing does not automatically push Git changes.
- Pushing Git changes does not automatically publish in Doki.
- A workspace can be linked to zero or one installed apps in v1.

This keeps development and release actions clearly separated.

## AI App Creation Model

The right long-term shape is not "a chat box wired directly to Ollama."

The right shape is:

- a provider-agnostic AI backend
- a workspace-scoped tool layer
- a Studio chat UI on top

This keeps Doki flexible as models, providers, and local runtimes change.

### Product stance

- Local-first AI should be supported well.
- Remote providers should be optional, not mandatory.
- AI-generated changes should be reviewable and auditable.
- AI should accelerate the manual workflow, not replace the workspace model.

### Provider model

Provider configuration should support:

- provider type
- label
- base URL
- auth secret reference
- default model
- model capability metadata
- enabled/disabled status

Recommended initial provider types:

- `ollama`
- `openai-compatible`

Do not bake App Studio around a single provider-specific API.

`openai-compatible` is the key future-proof choice because it can cover:

- OpenAI
- OpenRouter
- self-hosted gateways
- future providers that expose an OpenAI-style chat API

### Ollama connectivity model

Ollama should be treated as an endpoint, not a bundled dependency.

Recommended defaults:

- non-Docker Doki: `http://127.0.0.1:11434`
- containerized Doki: configurable host endpoint such as `http://host.docker.internal:11434` or a user-provided URL

Important rule:

- never hardcode `localhost` assumptions into the Studio UI
- keep the base URL server-side and testable from the Doki process that will actually make the request

The UI should include:

- `Test connection`
- `List models`
- provider health status

### Model selection strategy

Do not hardcode Doki to one exact model name.

Instead:

- let the admin choose a default model per provider
- allow Studio to override the model per workspace or chat session
- store lightweight capability metadata such as:
  - `isCodeModel`
  - `supportsTools`
  - `supportsStreaming`
  - `contextWindow`
  - `speedTier`

For local Ollama, the default behavior should be:

- prefer the smallest installed code-capable model that meets a minimum quality bar
- if none is installed, guide the user to configure one instead of silently failing

That is more robust than tying the product to one specific small coder model that may fall out of favor.

### Workspace tool surface

The model should not receive unrestricted shell access in v1.

It should get a constrained, workspace-scoped tool layer such as:

- list workspace files
- read file
- create file
- update file
- delete file
- inspect workspace metadata
- validate workspace
- sync preview

Optional follow-on tools:

- run smoke tests
- run Playwright
- git status

Do not allow AI-driven push or publish in the first version.

### Change application model

The safest v1 behavior is:

1. user prompts the assistant
2. assistant proposes file operations or patches
3. Doki shows a human-readable diff
4. user applies or rejects
5. workspace updates
6. preview and validation can run automatically

This preserves user trust and makes debugging much easier.

### Context strategy

Do not dump the entire repo into every request.

Build context progressively from:

- workspace metadata
- selected app path
- selected file
- `manifest.yaml`
- nearby files relevant to the task
- recent chat turns

This keeps local models usable and cheap providers practical.

### Persistence

Add a lightweight conversation/run history for AI activity, for example:

- `workspace_ai_sessions`
- `workspace_ai_messages`
- or a JSON-backed session log under workspace state

This history should store:

- provider
- model
- prompt summary
- affected files
- apply status
- timestamps

### Audit events

Add events for:

- `workspace.aiPrompt`
- `workspace.aiApply`
- `workspace.aiReject`
- `workspace.aiValidate`

### Recommended v1 AI UI

Inside App Studio, add:

- a chat panel
- provider selector
- model selector
- "include current file" toggle
- "include manifest" toggle
- diff preview panel
- apply and undo controls

The chat should feel native to the Studio editor, not like a separate chatbot product.

## Starter Templates

Ship a small set of opinionated starters under a new internal template location, for example:

```text
templates/app-starters/
  utility/
  crud/
  api-wrapper/
  dashboard/
```

Each starter should include:

- `manifest.yaml`
- `index.php`
- optional `settings.php`
- minimal storage usage example
- basic CSS/JS pattern consistent with Doki
- optional starter Playwright smoke test

## Proposed UI Surface

### New screens

#### `App Studio Home`

- recent workspaces
- create from starter
- import repo
- continue editing
- latest test status

#### `Workspace Overview`

- app identity
- linked repo and branch
- preview status
- latest validation result
- latest test result
- publish status

#### `Workspace Editor`

- file tree
- AI chat panel
- manifest form tab
- raw file editor tab
- git status tab
- preview pane
- test results pane

#### `Publish Review`

- version delta
- trust impact
- runtime impact
- changed files summary
- publish confirmation

## API Plan

Add a new API surface, for example `app/api/studio.php`.

### Suggested endpoints

- `GET action=list-workspaces`
- `POST action=create-starter-workspace`
- `POST action=import-repo-workspace`
- `GET action=get-workspace&id=...`
- `POST action=update-workspace-meta`
- `GET action=list-files&id=...`
- `GET action=get-file&id=...&path=...`
- `POST action=save-file`
- `POST action=create-file`
- `POST action=delete-file`
- `POST action=validate-workspace`
- `POST action=preview-workspace`
- `GET action=list-ai-providers`
- `POST action=test-ai-provider`
- `GET action=list-ai-models`
- `POST action=workspace-ai-chat`
- `POST action=workspace-ai-apply`
- `POST action=run-smoke-tests`
- `POST action=run-playwright`
- `GET action=git-status&id=...`
- `GET action=git-diff&id=...`
- `POST action=git-commit`
- `POST action=git-push`
- `POST action=git-pull`
- `POST action=create-branch`
- `POST action=publish-workspace`

This can start as one API file and be split later if needed.

## Permission Model

Add a dedicated permission surface for workspaces instead of overloading installed-app permissions.

Suggested permission buckets:

- `studio.read`
- `studio.write`
- `studio.ai`
- `studio.test`
- `studio.publish`
- `studio.gitPush`

Recommended default:

- admins: full App Studio access
- normal users: optional access, disabled by default until the UX is mature

## Safety Model

App Studio should be easy, but not permissive by accident.

### Safety rules

- New workspaces default to preview-only and private to the editor.
- Starter apps default to sandboxed capability requirements unless the starter explicitly needs more.
- Publishing always shows trust and runtime review.
- Git write actions are audited.
- Preview runtime is isolated from installed-app runtime state.
- AI actions are limited to the current workspace.
- AI provider secrets stay server-side.
- AI-generated changes are reviewable before apply.
- AI cannot push or publish on behalf of the user in v1.

### Possible future trust tier

The existing note in `NICE-TO-HAVE.md` about a stricter wrapped-app trust tier is still valuable.

Not needed for v1, but App Studio is compatible with a future `quarantined` or similar trust mode for imported third-party apps.

## Implementation Phases

### Phase 0: Foundation and schema

Goal: create the minimal workspace foundation without changing app install/update flows.

Deliverables:

- DB migration for `app_workspaces`
- `WorkspaceManager.php`
- workspace filesystem root
- basic create/open/list/delete workspace APIs
- starter workspace creation

Primary files:

- `app/includes/Database.php`
- new `app/includes/WorkspaceManager.php`
- new `app/api/studio.php`

### Phase 1: Live preview

Goal: make workspace apps viewable and editable inside Doki.

Deliverables:

- workspace file APIs
- preview routing
- workspace manifest validation
- preview status UI
- custom runtime support for workspace preview

Primary files:

- `app/includes/AppManager.php`
- `app/includes/AppImageBuilder.php`
- `app/includes/app-bootstrap.php`
- new or updated App Studio UI pages

### Immediate Phase: AI app creation

Goal: let a user describe an app or an edit in chat and turn that into workspace changes inside App Studio.

Deliverables:

- provider abstraction for local and remote AI
- Ollama connectivity from the Doki host process
- OpenAI-compatible provider support for APIs such as OpenAI and OpenRouter
- App Studio chat panel
- workspace-scoped AI read/write tools
- prompt-to-app generation
- diff review and apply flow
- audit events for AI actions

Primary files:

- new `app/includes/AIProviderManager.php`
- new `app/includes/WorkspaceAIManager.php`
- `app/includes/ConfigManager.php`
- `app/api/studio.php` or new `app/api/ai.php`
- `app/admin/studio.php`

### Phase 2: Test integration

Goal: make validation and Playwright testing part of the same workspace flow.

Deliverables:

- instant manifest validation
- smoke test runner
- workspace-linked Playwright execution
- latest run status in UI

Primary files:

- `app/includes/PlaywrightProjectManager.php`
- `app/api/playwright.php`
- `app/apps/playwright/*` or new App Studio UI components

### Phase 3: Git write support

Goal: make Doki capable of round-tripping changes back to GitHub/GitLab.

Deliverables:

- git status/diff
- branch operations
- commit
- push
- audit events for git actions

Primary files:

- `app/includes/SourcesManager.php`
- new `app/includes/WorkspaceGitManager.php`
- `app/api/studio.php`

### Phase 4: Publish and update flow

Goal: make workspace-to-installed-app publishing explicit and reliable.

Deliverables:

- publish review screen
- trust/runtime review
- publish to installed app
- link workspace to installed app
- update installed app from linked workspace

Primary files:

- `app/includes/AppManager.php`
- `app/api/marketplace.php`
- App Studio UI

### Phase 5: Low-code builder

Goal: reduce the barrier for non-technical users.

Deliverables:

- form-based app generator
- manifest editor
- starter-specific schema forms
- guided test generation

This phase should be built only after the underlying workspace model is stable.

## Recommended Build Order

If only one iteration can be funded first, build in this order:

1. Phase 0
2. Phase 1
3. Immediate Phase: AI app creation
4. Phase 3
5. Phase 2
6. Phase 4
7. Phase 5

Reason:

- preview is the first thing that makes the product feel real
- AI app creation becomes dramatically more useful once editable workspaces and preview already exist
- git write support is required for the repo round-trip promise
- testing is important, but users will tolerate a manual first version if preview and git already work

## Acceptance Criteria for v1

Doki v1 of App Studio is successful if a user can:

1. Create a starter app entirely in Doki.
2. Import an existing repo and detect the app path.
3. Describe an app or edit in chat and get a reviewable AI-generated change set.
4. Edit manifest and page files in a workspace.
5. Open a live preview inside Doki.
6. Run validation and at least one test flow.
7. Commit and push a change back to the connected repo.
8. Publish the workspace app into Doki with an explicit review step.

## Risks and Open Questions

### 1. Preview routing complexity

Question:

- how much of `app-bootstrap.php` can be reused without introducing fragile path assumptions?

Recommendation:

- build a thin workspace-aware preview wrapper before modifying the normal installed-app routing path.

### 2. Runtime duplication

Question:

- how much Docker/image churn will workspace previews create?

Recommendation:

- key preview runtime by workspace ID plus runtime hash and reuse aggressively.

### 3. Git safety

Question:

- how much git power should the browser expose in v1?

Recommendation:

- start with simple commit/push/pull/branch flows only, no rebase or force push.

### 4. Multi-app repos

Question:

- should one workspace support multiple app manifests in one repo?

Recommendation:

- yes for discovery, but keep one active app target per workspace in v1.

### 5. Testing UX overlap

Question:

- should this live inside the existing Playwright app or a new App Studio UI?

Recommendation:

- keep execution services shared, but make App Studio the orchestration UI.

## Immediate Next Steps

1. Add `app_workspaces` migration and `WorkspaceManager.php`.
2. Create a minimal `app/api/studio.php`.
3. Build `Create starter workspace` end to end.
4. Add workspace file editing.
5. Add live preview for workspace apps without custom runtime.
6. Add runtime-aware preview.
7. Add git status and commit/push.

## Decision

The recommended direction is:

- keep Doki's current source sync and installed-app flows
- add a separate workspace authoring model
- make App Studio the unified UX over workspace, preview, testing, git, and publishing

That gives Doki a credible app platform without breaking the simpler operational workflows it already supports.
