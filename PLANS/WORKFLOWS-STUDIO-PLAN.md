# Workflows Studio Implementation Plan

## Summary

This document defines the implementation plan for `Workflows Studio`, a new Studio-side authoring surface for Doki workflows and reusable workflow templates.

The intent is:

1. Add a real `Studio` section in navigation.
2. Move the current `App Studio` page into that section.
3. Add a new `Workflows Studio` page inside the same section.
4. Let admins build workflows and reusable templates manually or by asking AI.
5. Reuse the workflows AI context settings that already exist today.
6. Keep the existing `Commands`, `Templates`, and `History` pages for runtime operations, execution, and quick inspection.

In short:

- `Workflows` stays the operator/runtime area.
- `Studio` becomes the authoring/build area.

That split is important. Today Doki already has an execution-oriented workflows surface and an authoring-oriented app studio surface. This plan brings workflows authoring into the same Studio mental model instead of trying to overload the runtime pages.

## Current State

### Existing product pieces

Doki already has the core parts needed to build this:

- Workflow runtime definitions live in [`app/data/config/commands.yaml`](../app/data/config/commands.yaml) and are managed by [`app/includes/CommandsManager.php`](../app/includes/CommandsManager.php).
- Runtime templates are currently file-based and effectively read-only through [`app/includes/TemplateManager.php`](../app/includes/TemplateManager.php), which loads from the shipped [`templates`](../templates) directory.
- Source discovery already understands template repositories through [`app/includes/SourcesManager.php`](../app/includes/SourcesManager.php).
- Runtime workflow UI lives in [`app/commands.php`](../app/commands.php).
- Workflow CRUD endpoints live in [`app/api/commands.php`](../app/api/commands.php).
- App Studio already exists in [`app/admin/studio.php`](../app/admin/studio.php).
- App Studio workspaces already exist through [`app/includes/WorkspaceManager.php`](../app/includes/WorkspaceManager.php) and the `app_workspaces` table in [`app/includes/Database.php`](../app/includes/Database.php).
- App Studio AI already exists through:
  - [`app/includes/WorkspaceAIManager.php`](../app/includes/WorkspaceAIManager.php)
  - [`app/includes/WorkspaceAIJobManager.php`](../app/includes/WorkspaceAIJobManager.php)
  - [`app/studio-ai-job.php`](../app/studio-ai-job.php)
- AI providers and AI context blocks already exist through:
  - [`app/admin/ai.php`](../app/admin/ai.php)
  - [`app/includes/AIAdminManager.php`](../app/includes/AIAdminManager.php)
  - `ai_context_blocks` in [`app/includes/Database.php`](../app/includes/Database.php)

### Existing workflows AI context

The existing AI admin implementation already includes a `commands` scope with editable context blocks for:

- command overview
- template/schema rules
- targets, environments, and secrets
- runtime and execution safety
- permissions and safety
- examples and patterns

Those blocks are already the correct conceptual foundation for workflow AI. The main problem is product surface and naming, not lack of context infrastructure.

### Current gaps

What is missing today:

- no dedicated workflow authoring studio
- no draft model separate from published commands
- no template draft model separate from published templates
- no AI workflow generation flow
- no AI template generation flow
- no Studio-side workflow validation/review/publish loop
- no navigation model that groups App Studio and workflow authoring together

## Product Goals

### Primary goals

- Add a first-class `Workflows Studio` page under `Studio`.
- Make workflow authoring possible without writing raw YAML.
- Make template authoring possible without editing shipped template files directly.
- Support both manual authoring and AI-assisted authoring.
- Let AI create a first draft from plain-language intent.
- Let AI create reusable templates when the workflow logic would otherwise be repeated across many workflows.
- Support source-backed template flows similar to App Studio: import from repo, edit in Studio, and push changes back to the source repository.
- Keep publish/save behavior compatible with the current command runtime.
- Reuse the existing workflows AI context settings instead of inventing a separate parallel system.

### Secondary goals

- Make the Studio experience feel consistent between apps and workflows.
- Add draft/save/versioning so users can iterate without immediately mutating live workflows.
- Preserve auditability and operator trust.

### Non-goals for v1

- Visual DAG editor with drag-and-drop edges
- Automatic workflow execution by AI
- Multi-user collaborative workflow editing
- Git-backed workflow repositories
- Full marketplace/package publishing flow for custom templates

## Product Model

### Mental model

We should treat workflow authoring as a draft/build process, not just a prettier wrapper around `commands.yaml`.

That means the product model should be:

- `Commands / Templates / History`
  - run workflows
  - inspect workflows
  - inspect published templates
  - perform quick edits
  - review execution history

- `Studio / App Studio`
  - build apps
  - preview apps
  - publish apps

- `Studio / Workflows Studio`
  - build workflow drafts
  - build reusable template drafts
  - validate them
  - generate or refine them with AI
  - publish workflows into the runtime command registry
  - publish templates into the runtime template registry

### Why this split matters

The current `Commands` page is an operations page. It assumes the workflow already exists and is close to runnable. Workflows Studio should instead optimize for creation, iteration, explanation, and publishing.

## Navigation and Module Changes

### Sidebar structure target

Add a new sidebar section in [`app/includes/SystemModuleRegistry.php`](../app/includes/SystemModuleRegistry.php):

- `Studio`

Move/add modules like this:

- `studio`
  - label: `App Studio`
  - page: existing [`app/admin/studio.php`](../app/admin/studio.php)
  - section: `studio`

- `studio.workflows`
  - label: `Workflows`
  - page: new [`app/admin/studio-workflows.php`](../app/admin/studio-workflows.php)
  - section: `studio`

Keep the current runtime-facing workflow modules where they are:

- `commands`
- `templates`
- `history`

### Permission model

Recommended permission behavior:

- `studio`
  - gates App Studio page access
- `studio.workflows`
  - gates Workflows Studio page access for both workflow and template authoring
- `studio.ai`
  - gates AI creation inside both App Studio and Workflows Studio
- `ai.admin`
  - still gates provider and context configuration

### Onboarding/module-selection impact

The onboarding module choice currently maps `App Studio` to `studio`.

After this project, that should become a Studio group:

- `studio`
- `studio.workflows`

The onboarding label can stay simple:

- `App Studio`

But internally it should enable the whole Studio authoring group unless we later decide to expose separate Studio toggles.

## Scope Naming Decision

### Recommendation

For implementation speed, keep the stored AI context scope key as `commands` for now, but relabel it everywhere in product/UI copy as `Workflows Context`.

Why:

- the DB already stores `ai_context_blocks.scope IN ('apps', 'commands')`
- the current context content already covers commands and templates together
- renaming the scope in the DB is possible, but not required to unlock Workflows Studio

### Follow-up cleanup

After Workflows Studio is stable, we can decide whether to:

1. keep `commands` as the storage key forever but present it as `Workflows`
2. migrate the DB/API/UI from `commands` to `workflows`

Recommendation: do not block v1 on a scope migration.

## Architecture Decision

### Core rule

Published workflows continue to be stored and executed through the existing command runtime.

That means:

- `commands.yaml` remains the source of truth for published/runnable workflows
- Workflows Studio manages drafts and publish workflows
- publish writes through [`CommandsManager::saveCommand()`](../app/includes/CommandsManager.php)

This avoids creating a second execution engine.

### Template publish rule

Published reusable templates should become first-class runtime templates too, but they should not be written into the shipped [`templates`](../templates) directory.

Recommended publish target:

- add a runtime custom templates directory such as `app/data/templates`
- extend [`TemplateManager.php`](../app/includes/TemplateManager.php) to load:
  - built-in templates from [`templates`](../templates)
  - published custom templates from `app/data/templates`

That keeps:

- built-in templates repo-owned
- custom templates runtime-owned
- template publishing safe in fresh installs and resets

### Source-backed template rule

Templates should support the same broad authoring modes as apps:

- local-only draft created in Studio
- imported draft from a Git source
- locally published runtime template
- repo-backed template project that can be pulled, edited, committed, and pushed

That means Workflows Studio should not be local-only for templates. It should integrate with the existing Sources system in the same spirit as App Studio.

## Draft Model

We should not edit live published commands directly while a user is mid-iteration in Studio.

Instead, introduce draft objects.

### New concept: workflow project

A `workflow project` is a Studio-side draft representation of a workflow that may or may not yet be published as a command.

Suggested statuses:

- `draft`
- `published`
- `archived`

### Why a project layer is necessary

Without a project layer:

- AI would overwrite live commands too early
- there would be no versioning or review step
- manual authoring would still feel like editing raw runtime config

The project layer gives us:

- safe iteration
- AI diff/review/apply
- explicit publish step
- restore/version history

### New concept: template project

A `template project` is a Studio-side draft representation of a reusable template that may or may not yet be published to the runtime template catalog.

Suggested statuses:

- `draft`
- `published`
- `archived`

Why this matters:

- templates are shared building blocks, so editing a live template directly is risky
- AI should be able to propose new templates safely before they affect many workflows
- users need the same review/publish lifecycle for templates that they get for workflows
- source-backed templates need a writable Studio layer between repo discovery and runtime publication

## Data Model

### New table: `workflow_studio_projects`

Suggested columns:

- `id`
- `name`
- `description`
- `slug`
- `status` (`draft`, `published`, `archived`)
- `source_type` (`manual`, `ai`, `clone`)
- `linked_command_id`
- `draft_json`
- `last_published_json`
- `last_validation_json`
- `ai_summary`
- `created_at`
- `created_by`
- `updated_at`
- `updated_by`
- `published_at`
- `published_by`

### New table: `workflow_studio_versions`

Suggested columns:

- `id`
- `project_id`
- `version_number`
- `snapshot_json`
- `summary`
- `source` (`manual-save`, `ai-apply`, `publish`, `restore`)
- `created_at`
- `created_by`

### New table: `template_studio_projects`

Suggested columns:

- `id`
- `name`
- `description`
- `slug`
- `status` (`draft`, `published`, `archived`)
- `source_type` (`manual`, `ai`, `clone`, `repository`)
- `provider_id`
- `repository_id`
- `repo_url`
- `default_branch`
- `current_branch`
- `linked_template_id`
- `template_path`
- `workspace_root`
- `draft_json`
- `last_published_json`
- `last_validation_json`
- `ai_summary`
- `last_sync_at`
- `created_at`
- `created_by`
- `updated_at`
- `updated_by`
- `published_at`
- `published_by`

### New table: `template_studio_versions`

Suggested columns:

- `id`
- `project_id`
- `version_number`
- `snapshot_json`
- `summary`
- `source` (`manual-save`, `ai-apply`, `publish`, `restore`)
- `created_at`
- `created_by`

### Optional table: `workflow_studio_messages`

Only needed if we want to persist AI conversation history separately from versions.

Suggested columns:

- `id`
- `project_id`
- `role`
- `content`
- `model`
- `provider_id`
- `created_at`
- `created_by`

This table is optional for v1. A lighter alternative is to persist only the most recent AI result and versions.

## Reuse of existing tables

### Reuse `studio_ai_jobs`

Do not create a second AI jobs table.

Instead, generalize the current `studio_ai_jobs` table to support workflow project jobs too.

Recommended change:

- add `subject_type` (`app_workspace`, `workflow_project`, `template_project`)
- add `subject_id`
- keep `workspace_id` temporarily for backward compatibility during migration

New job kinds:

- `workflow_chat`
- `create_workflow_project`
- `template_chat`
- `create_template_project`

Longer term:

- `WorkspaceAIJobManager` should become a more generic `StudioAIJobManager`
- or a new generic job manager should sit above app/workflow-specific AI managers

### Keep `commands.yaml` as publish target

Published workflows should still be written via [`CommandsManager`](../app/includes/CommandsManager.php), not a new runtime registry.

### Add runtime custom template registry

Published templates should be written to a runtime-owned directory and loaded by [`TemplateManager`](../app/includes/TemplateManager.php), rather than changing the shipped template set in-place.

Recommendation:

- built-in templates remain in [`templates`](../templates)
- published custom templates live in `app/data/templates`
- resets may clear custom templates while preserving built-ins

## Source-Backed Template Flows

Templates should have repo-backed flows similar to apps, with Studio acting as the writable layer.

### Core user journeys

#### 1. Import template from source repo

1. User opens Workflows Studio.
2. User clicks `Import Template`.
3. User selects a provider, repository, branch, and template path.
4. Doki creates a writable template project linked to that source.
5. User edits the template in Studio.
6. User validates, saves versions, and pushes changes back to the repository.

#### 2. Create template locally, then connect to source

1. User creates a new template draft manually or with AI.
2. User validates and publishes it locally for immediate runtime use.
3. User later links it to a repository.
4. Doki creates the expected file structure and commits/pushes it to the selected branch.

#### 3. Clone discovered source template into a Studio draft

1. User discovers a template through the Sources area.
2. User clicks `Open in Workflows Studio`.
3. Doki creates a template project linked to that source template.
4. User can iterate safely before publishing locally or pushing changes upstream.

### Required behaviors

- `Sources` should expose templates as Studio-openable assets, not just read-only discovered objects.
- Workflows Studio should show whether a template project is:
  - local-only
  - linked to a source repository
  - ahead/behind the remote branch
  - diverged from the last locally published runtime template
- Template projects should support pull, commit, and push flows similar to App Studio workspaces.
- Importing a source template should not immediately mutate the locally published runtime template unless the user explicitly publishes it.

## Workflow Draft Shape

The draft JSON should intentionally mirror the normalized command schema as closely as possible.

Suggested draft shape:

```json
{
  "id": "deploy-api",
  "name": "Deploy API",
  "description": "Build and deploy the API service",
  "executionMode": "sync",
  "maxParallel": 1,
  "finalStatusPolicy": "fail_if_any_failed",
  "defaultTarget": { "targetId": "local" },
  "environment": "production",
  "steps": [
    {
      "id": "build",
      "templateId": "shell-exec",
      "target": "inherit",
      "dependsOn": [],
      "onFailure": "stop",
      "inputs": {
        "command": "docker compose build api"
      },
      "secrets": {}
    }
  ],
  "inputs": {},
  "inputContract": [],
  "secrets": {},
  "tags": ["deploy"],
  "enabled": true
}
```

### Important rule

Workflows Studio should not invent a second workflow DSL.

It should author the same command shape the runtime already understands.

## Template Draft Shape

Template drafts should mirror the normalized runtime template model as closely as possible.

Suggested draft shape:

```json
{
  "id": "deploy-service",
  "name": "Deploy Service",
  "version": "1.0.0",
  "description": "Reusable deployment template for containerized services",
  "icon": "fas fa-rocket",
  "color": "#2563eb",
  "targetType": "docker",
  "runtime": {
    "image": "alpine:latest"
  },
  "inputs": [
    {
      "name": "service",
      "type": "text",
      "required": true,
      "label": "Service name"
    },
    {
      "name": "environment",
      "type": "text",
      "required": true
    }
  ],
  "execution": {
    "mode": "sync",
    "timeout": 1800
  },
  "output": {
    "type": "text"
  },
  "requirements": [
    "exec.docker"
  ],
  "targetBindings": []
}
```

### Important rule

Template drafts should map to the existing runtime template format, not a Studio-only format.

## Backend Components

### New manager: `WorkflowStudioManager.php`

Responsibilities:

- create/update/delete projects
- normalize draft payloads
- snapshot versions
- validate draft structure
- publish draft to command runtime
- clone existing commands into projects
- restore versions

Key methods:

- `listProjects()`
- `getProject($id)`
- `createProject($data, $userId, $username)`
- `updateProjectDraft($id, $draft, $userId, $username)`
- `validateProject($id)`
- `publishProject($id, $userId, $username)`
- `cloneCommandToProject($commandId, $userId, $username)`
- `restoreVersion($projectId, $versionId, $userId, $username)`

### New manager: `TemplateStudioManager.php`

Responsibilities:

- create/update/delete template projects
- normalize template draft payloads
- snapshot versions
- validate draft structure against runtime template rules
- publish draft to the runtime custom template registry
- clone existing templates into projects
- restore versions
- coordinate source-backed template projects through `SourcesManager`

Key methods:

- `listProjects()`
- `getProject($id)`
- `createProject($data, $userId, $username)`
- `updateProjectDraft($id, $draft, $userId, $username)`
- `validateProject($id)`
- `publishProject($id, $userId, $username)`
- `cloneTemplateToProject($templateId, $userId, $username)`
- `restoreVersion($projectId, $versionId, $userId, $username)`
- `importRepositoryTemplateProject($data, $userId, $username)`
- `syncTemplateProject($projectId, $userId, $username)`
- `commitTemplateProject($projectId, $message, $userId, $username)`
- `pushTemplateProject($projectId, $userId, $username)`

### New AI manager: `WorkflowAIManager.php`

Responsibilities:

- build workflow-specific AI prompts
- include workflows context blocks
- include live Doki references such as templates, targets, environments, current workflow draft, and current template drafts when relevant
- return structured workflow/template draft updates instead of file operations

Recommended response shape:

```json
{
  "reply": "short assistant message",
  "summary": "one-line summary",
  "workflow": { "...normalized workflow draft json..." },
  "templates": [
    {
      "id": "deploy-service",
      "summary": "Reusable deployment template extracted from repeated deployment steps",
      "template": { "...normalized template draft json..." }
    }
  ],
  "notes": ["optional notes"],
  "warnings": ["optional warnings"],
  "questions": ["only if blocked"]
}
```

### New API: `app/api/studio-workflows.php`

Suggested actions:

- `list-projects`
- `get-project`
- `create-project`
- `update-draft`
- `delete-project`
- `validate-project`
- `publish-project`
- `duplicate-project`
- `clone-command`
- `list-versions`
- `restore-version`
- `list-template-projects`
- `get-template-project`
- `create-template-project`
- `update-template-draft`
- `delete-template-project`
- `validate-template-project`
- `publish-template-project`
- `duplicate-template-project`
- `clone-template`
- `list-template-versions`
- `restore-template-version`
- `queue-ai-chat`
- `queue-ai-create`
- `get-ai-job`
- `apply-ai-result`

### Audit events

Add audit coverage for:

- workflow project created
- workflow project updated
- workflow project deleted
- workflow version restored
- workflow published
- template project created
- template project updated
- template project deleted
- template version restored
- template published
- workflow AI requested
- workflow AI result applied

## Validation Layer

Validation should happen before publish and should also be available during editing.

### Validation sources

Use existing runtime rules where possible:

- [`CommandsManager::validateCommand()`](../app/includes/CommandsManager.php)
- template existence through [`TemplateManager`](../app/includes/TemplateManager.php)
- target existence/capability checks through existing config managers
- environment existence through existing config managers

For templates:

- validate against the runtime template schema and required fields loaded by [`TemplateManager.php`](../app/includes/TemplateManager.php)
- validate target type / runtime requirements shape
- validate inputs, execution, output, and target bindings before publish

### Studio validation output

Validation output should be structured, not just a flat error string.

Suggested categories:

- schema errors
- missing template references
- invalid template definitions
- missing target references
- invalid dependency graph
- unsafe defaults
- publish warnings

### Validation behavior

- save draft even if validation fails
- block publish on hard validation failures
- allow publish with warnings if the underlying runtime model would allow it

## Frontend Surface

### New page: `app/admin/studio-workflows.php`

This page should visually feel like a sibling of App Studio, not a copy of the runtime Commands page.

### Page layout

Recommended layout:

- left rail: project list / recent drafts
- center: workflow/template builder
- right panel: validation, publish status, AI notes

Alternative on small screens:

- stacked tabs for `Workflows`, `Templates`, `AI`, `Validation`, `Publish`

### Main entry actions

- `New Workflow`
- `New Template`
- `Create with AI`
- `Clone Existing Command`
- `Clone Existing Template`
- `Open Draft`

### Manual builder sections

Suggested sections:

1. Workflow details
   - ID
   - name
   - description
   - tags
   - enabled

2. Execution settings
   - execution mode
   - max parallel
   - final status policy
   - default target
   - environment

3. Steps
   - ordered list of steps
   - template picker
   - per-step target override
   - dependency configuration
   - failure handling
   - step inputs
   - step secrets references

4. Workflow inputs
   - shared inputs
   - input contract
   - defaults

5. Publish
   - validation summary
   - overwrite confirmation if linked to existing command

### Template builder sections

Suggested sections:

1. Template details
   - ID
   - name
   - description
   - icon
   - color
   - version

2. Runtime model
   - target type
   - runtime image
   - execution mode
   - timeout

3. Template inputs
   - input list
   - required flags
   - defaults
   - descriptions
   - type metadata

4. Output and bindings
   - output type
   - requirements
   - target bindings

5. Publish
   - validation summary
   - overwrite confirmation if linked to an existing published template

### AI builder surface

The AI panel should support:

- plain-language request input
- optional refinement prompt against current draft
- visible model/provider selection
- structured result preview
- accept/reject/apply flow
- ability to review both workflow changes and any proposed new/updated templates
- preserving repository-linked template drafts without breaking their source linkage

The user should never be surprised by AI mutating the draft without review.

## AI Strategy

### Primary AI use case

The user should be able to type something like:

> Create a workflow that deploys the API to staging, runs smoke checks, and posts a summary.

And receive:

- a draft workflow
- zero or more reusable template drafts when reuse is beneficial
- an explanation of how it was modeled
- warnings if required templates/targets are missing

### AI input context

The AI request should include:

- workflows context blocks from [`AIAdminManager`](../app/includes/AIAdminManager.php)
- available templates
- source-linked template metadata when the user is editing a repository-backed template
- targets and capabilities
- environments
- current draft JSON, if editing
- current template drafts, if editing or if the workflow already depends on draft templates
- selected step or selected field, if refining
- the publish constraints of the current runtime schema

### AI guardrails

The AI must:

- prefer known templates when they already fit the request
- propose creating or updating reusable templates when repeated logic would otherwise be duplicated across workflows
- never invent real secret values
- reference secret IDs, targets, and environments rather than hardcoding values
- avoid destructive steps unless the prompt clearly requires them
- return warnings when the request is underspecified
- never publish templates or workflows automatically

### AI result apply model

The safest approach is:

1. AI returns a proposed asset bundle: workflow draft plus any template drafts it wants to add or update
2. Doki computes a structured diff against the current workflow/template drafts
3. user reviews the diff
4. Doki applies the drafts only after explicit confirmation

Do not let the AI directly publish.

## Relationship to Existing Commands Page

### What stays in `Commands`

The runtime `Commands` page should continue to handle:

- browsing commands
- quick editing
- execution
- operator-facing metadata

The runtime `Templates` page should continue to handle:

- browsing published templates
- inspecting template definitions
- runtime validation support

The `Sources` area should continue to handle:

- repository/provider setup
- discovery of template-capable repositories
- opening source-backed templates in Workflows Studio

### What moves to Workflows Studio

Workflows Studio becomes the preferred place for:

- drafting new workflows
- drafting new templates
- AI-assisted workflow creation
- AI-assisted template creation
- version restore
- guided validation and publish flow

### Integration points

Add links both ways:

- `Commands` page: `Open in Workflows Studio`
- `Workflows Studio`: `Open Runtime View`
- `Templates` page: `Open in Workflows Studio`
- `Workflows Studio`: `Open Published Template`

This prevents the product from feeling split into unrelated silos.

## Implementation Phases

### Phase 0: Navigation and naming

- Add `Studio` section to [`SystemModuleRegistry.php`](../app/includes/SystemModuleRegistry.php)
- Move existing `studio` module into the new section
- Add new `studio.workflows` module
- Update admin settings/module labels
- Relabel AI admin `commands` scope as `Workflows Context` in UI copy

Exit criteria:

- sidebar shows a real Studio section
- App Studio lives there
- a placeholder Workflows Studio page exists
- permissions work

### Phase 1: Draft model and manual builder

- Add DB tables for workflow projects/versions and template projects/versions
- Implement `WorkflowStudioManager`
- Implement `TemplateStudioManager`
- Build manual workflow builder UI
- Build manual template builder UI
- Validate drafts against current runtime rules
- Publish drafts through `CommandsManager`
- Publish templates through the runtime custom template registry and `TemplateManager`

Exit criteria:

- an admin can create a workflow draft manually
- an admin can create a template draft manually
- validation is visible before publish
- publish creates or updates a runnable command
- publish creates or updates a reusable published template
- drafts are versioned

### Phase 2: AI-assisted workflow creation

- Add workflow AI manager
- generalize Studio AI jobs
- build `Create with AI` flow
- add AI result review/apply
- use existing workflows context settings

Exit criteria:

- AI can create a usable first draft from plain language
- AI can create reusable template drafts when needed
- AI changes are reviewed before apply
- provider/model selection works
- workflows context settings materially influence output

### Phase 3: Source-backed template workflows

- connect template projects to `SourcesManager`
- add `Import Template` flow from GitHub/GitLab/etc.
- support repository-linked template drafts
- add commit/push/pull flows for template projects
- add `Open in Workflows Studio` from the Sources area

Exit criteria:

- a user can import a template from a source repository into Workflows Studio
- a user can edit and version that template safely in Studio
- a user can push template changes back to the source repository
- locally published templates remain separate from draft/source-linked state until explicit publish

### Phase 4: Integration and polish

- add clone-from-command flow
- add clone-from-template flow
- add links between Commands and Workflows Studio
- add links between Templates and Workflows Studio
- add links between Sources and Workflows Studio for templates
- add publish history/version restore polish
- add draft summaries and recent activity
- improve warning UX for missing templates/targets

Exit criteria:

- Workflows Studio feels like the preferred authoring surface
- runtime pages and Studio pages are clearly connected
- users can move between draft and live command views cleanly

## Recommended File Additions

### Backend

- `app/includes/WorkflowStudioManager.php`
- `app/includes/TemplateStudioManager.php`
- `app/includes/WorkflowAIManager.php`
- `app/api/studio-workflows.php`

### UI

- `app/admin/studio-workflows.php`

### Optional refactors

- rename/generalize `WorkspaceAIJobManager.php` into a shared Studio AI job manager
- add shared Studio AI polling helpers used by both App Studio and Workflows Studio

## Testing Plan

### Backend tests/manual verification

- create draft
- create template draft
- import source-backed template draft
- save partial invalid draft
- save partial invalid template draft
- validate invalid draft
- validate invalid template draft
- publish valid draft
- publish valid template draft
- republish over existing command
- republish over existing template
- clone existing command into draft
- clone existing template into draft
- pull source changes into a template project
- push template project changes back to source
- restore older version
- reject publish when template ID is missing
- reject publish when target reference is missing

### AI-specific tests

- AI create from minimal prompt
- AI refine existing draft
- AI proposes a reusable template when repeated steps are requested
- AI creates a standalone template from a plain-language request
- AI refines a source-backed template draft without breaking repo linkage
- AI request with missing required target/template
- AI output that proposes dangerous behavior
- AI apply review path
- AI context changes in admin reflected in new workflow generations

### Permission tests

- user without `studio.workflows` cannot access the page
- user with `studio.workflows` but without `studio.ai` can still use manual builder
- user with `studio.ai` can use AI creation
- module visibility rules still work for Studio navigation

## Risks

### Risk: second workflow model

If we let Workflows Studio drift away from the command runtime schema, we will create two workflow systems.

Mitigation:

- draft schema must mirror command schema
- publish path must go through `CommandsManager`

### Risk: AI invents unsupported templates

Mitigation:

- provide explicit template catalog and runtime template schema in prompt context
- fail validation clearly
- require review before apply

### Risk: second template model

If template drafts drift away from the runtime template format, Doki will end up with two incompatible template systems.

Mitigation:

- template draft schema must map to the runtime template shape
- published custom templates must be loaded by `TemplateManager`
- do not invent a Studio-only template DSL

### Risk: source-backed template drift

If source-linked template projects and locally published runtime templates are not clearly separated, users may not understand what is draft state versus live runtime state.

Mitigation:

- keep source project state, Studio draft state, and published runtime state explicit in the UI
- require explicit publish to affect runtime
- require explicit sync/push actions to affect the linked repository

### Risk: product confusion between Commands and Workflows Studio

Mitigation:

- runtime vs authoring split must be explicit in copy and navigation
- add links between both surfaces

### Risk: context naming confusion

Mitigation:

- relabel `commands` scope as `Workflows Context` in UI immediately
- defer storage-key migration until later

## Open Questions

### 1. Should Workflows Studio support template authoring in v1?

Recommendation:

- yes
- template authoring should ship with the same draft/published/archived lifecycle as workflows

### 2. Should draft changes autosave?

Recommendation:

- yes, autosave drafts
- publish remains explicit

### 3. Should we let AI create new templates as part of workflow generation?

Recommendation:

- yes
- AI should be allowed to create or update template drafts when that improves reuse, but those drafts must still go through review and explicit publish

### 4. Should the current Commands page keep full edit support?

Recommendation:

- yes initially
- later we can reduce it to quick edit once Workflows Studio is mature

## Final Recommendation

Build Workflows Studio as a draft-and-publish authoring system under a new `Studio` section, not as a replacement for runtime workflows pages.

Reuse:

- the current command runtime schema
- the existing AI providers
- the existing workflows context settings
- the existing Studio AI job model

Add:

- a workflow draft model
- a template draft model
- a workflow-specific builder UI
- a template builder UI
- source-backed template project flows similar to App Studio
- AI-assisted workflow and template generation with explicit review before apply

That gives Doki a coherent Studio story:

- App Studio for apps
- Workflows Studio for workflows and templates
- AI available in both
- runtime pages still focused on execution and operations

---

## Over-the-Top Ideas

This section is intentionally out of scope for the first implementation pass. It exists to capture bigger ideas that could make Workflows Studio feel unusually powerful later.

### Nice to haves

- Visual graph mode for workflows, where steps and dependencies can be edited as a simple DAG instead of only as a form list.
- Reusable workflow starters such as `deploy service`, `run smoke tests`, `restart + verify`, `db backup`, and `incident triage`.
- “Explain this workflow” mode that turns the current draft into a plain-language runbook for operators.
- AI-assisted workflow refactors, such as “split this into safer steps”, “make this reusable”, or “turn hardcoded values into inputs”.
- Template recommendations while building, so Doki suggests the best matching template for a new step based on the workflow goal.
- Draft comparison against the currently published version with a clearer operator-facing summary, not just structural diff data.
- Safer publish checks that warn when a workflow introduces destructive actions, high-privilege targets, or new secret dependencies.

### Very nice to haves

- Execution simulator that shows the exact order of steps, inherited targets, environment resolution, and failure propagation before publish.
- AI-generated test plan for a workflow, including suggested dry-run, smoke-check, and rollback scenarios.
- “Workflow from history” creation flow that turns a successful execution pattern into a reusable draft.
- Approval-ready publish notes generated automatically from the workflow diff, aimed at reviewers or change-management processes.
- Inline step-level copilot that can rewrite only one step while preserving the rest of the workflow unchanged.
- Shared workflow snippets or partials, so common sequences like auth, health check, or notification can be inserted into many workflows.

### Extremely over-the-top ideas

- Conversational workflow canvas where the user edits the workflow by chatting directly with the graph, with the graph updating live as AI proposes structural changes.
- Multi-path workflow design mode that can generate fallback branches, rollback branches, and degraded-mode branches from a single plain-language request.
- “What could go wrong?” risk review powered by AI, where Doki critiques the workflow like an SRE or security reviewer before publish.
- Workflow-to-app bridge, where a workflow can later be promoted into a small operator-facing app or dashboard without starting from scratch.
- Fleet-aware workflow generation that adapts one workflow draft for multiple environments, regions, or target groups while preserving one high-level intent.
- Self-documenting operations pack where Doki generates the workflow, the operator notes, the runbook summary, the rollback instructions, and the approval summary together as one package.
