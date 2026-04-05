# Workflows Studio AI History Implementation Status

## Current State

- Workflows Studio AI chat renders inside [`app/admin/studio-workflows.php`](../app/admin/studio-workflows.php) and keeps messages only in browser state via `studioState.aiMessages`.
- AI draft generation is handled by `generate-ai-draft` in [`app/api/studio-workflows.php`](../app/api/studio-workflows.php), but the backend does not persist chat messages, proposal metadata, or apply/revert history.
- Workflow and template projects already persist draft versions through:
  - [`app/includes/WorkflowStudioManager.php`](../app/includes/WorkflowStudioManager.php)
  - [`app/includes/TemplateStudioManager.php`](../app/includes/TemplateStudioManager.php)
  - [`app/includes/Database.php`](../app/includes/Database.php)
- The current diff UI can already render `draftBefore` and `draftAfter`, so it is a good base for historical proposal review.
- Existing permissions only distinguish:
  - `studio.workflows`
  - `studio.ai`
- There is no server-side concept of:
  - per-user AI threads
  - immutable AI history events
  - cross-user visibility rules for Studio AI history
  - persisted AI apply/revert checkpoints

## Goals

- Persist the full AI chat history for each workflow/template project.
- Show user tabs at the top of the chat for every user who has asked AI something on the current project.
- Let super-admins inspect all user tabs.
- Let normal users see only the history they are allowed to see.
- Prevent anyone, including super-admins, from continuing another user's AI thread.
- Prevent new prompts from being appended to historical snapshots.
- Show whether each AI proposal was proposed, applied, dismissed, reverted, saved, or published.
- Reuse the current diff UI for any historical proposal.
- Support reverting the project to a previously applied AI checkpoint.
- Keep all state changes auditable and conflict-safe.

## Product Rules

- AI history is project-scoped.
- The stored history is the full chat and action timeline for the project, even if AI prompt-building still uses a bounded recent context window.
- Each thread belongs to exactly one authoring user.
- A project can have multiple threads, one per user who used AI on that project.
- Super-admin can view every thread.
- Non-super-admin users can only view other users' threads if they have an explicit permission.
- Only the thread owner can continue the live thread with a new prompt.
- Only the thread owner can apply a pending proposal from that live thread.
- There is no cross-user "continue thread" capability.
- There is no cross-user "apply another user's pending proposal" capability.
- Historical checkpoints are read-only.
- Viewing another user's thread is read-only.
- Reverting to a checkpoint changes the shared project draft, but does not grant permission to continue that thread.
- Revert is only available for applied checkpoints, not for raw un-applied proposals.
- `Go back to this point` restores the draft to the checkpoint's applied result snapshot, meaning the state immediately after that historical AI change was applied.
- Dismissed proposals stay visible in the timeline and are labeled as not applied.
- Save, publish, and related project events appear in visible history for users allowed to see that thread.
- Cross-user revert controls are shown only to users who are allowed to revert that checkpoint.
- Every AI proposal that can affect the draft must persist `before` and `after` snapshots.

## Scope

### In scope for v1

- Persistent AI threads and events for workflow and template projects
- User tabs in the chat header
- History timeline with proposal state badges
- View diff and smart diff for historical entries
- Server-side apply, dismiss, and revert endpoints
- Revert to previously applied AI checkpoints
- Permission-based visibility for other users' threads
- Auto-create project before first AI prompt when needed
- Version entries for AI apply/revert actions

### Out of scope for v1

- Real-time multi-user collaborative editing
- Merging multiple users' AI threads into a shared writable conversation
- Branching a new prompt directly from an old historical checkpoint
- Background summarization or archival of old AI threads
- Cross-project AI history search UI

## Proposed Architecture

### 1. Persistence model

Add dedicated Studio AI history tables instead of relying on audit log text blobs.

Suggested tables:

- `workflow_studio_ai_threads`
  - `id`
  - `project_type` (`workflow` or `template`)
  - `project_id`
  - `owner_user_id`
  - `owner_username`
  - `title`
  - `status` (`active`, `archived`)
  - `last_event_at`
  - `created_at`
  - `created_by`

- `workflow_studio_ai_events`
  - `id`
  - `thread_id`
  - `project_type`
  - `project_id`
  - `event_type` (`user-prompt`, `assistant-proposal`, `assistant-error`, `apply`, `dismiss`, `revert`, `save`, `publish`)
  - `actor_user_id`
  - `actor_username`
  - `provider_id`
  - `provider_name`
  - `model`
  - `prompt_text`
  - `message_text`
  - `summary`
  - `notes_json`
  - `actions_json`
  - `validation_before_json`
  - `validation_after_json`
  - `draft_before_json`
  - `draft_after_json`
  - `base_version_id`
  - `result_version_id`
  - `proposal_status` (`proposed`, `applied`, `dismissed`, `reverted`, `superseded`, `error`)
  - `applied_event_id`
  - `reverted_event_id`
  - `created_at`

Recommended indexes:

- `(project_type, project_id, last_event_at DESC)` on threads
- `(thread_id, created_at ASC, id ASC)` on events
- `(project_type, project_id, event_type, created_at DESC)` on events
- `(owner_user_id, project_type, project_id)` on threads

Additional event metadata to expose in normalized API payloads:

- whether a proposal was ever applied
- who applied it
- when it was applied
- whether it was later reverted
- who reverted it
- when it was reverted

### 2. Versioning integration

Extend project version sources in:

- [`app/includes/WorkflowStudioManager.php`](../app/includes/WorkflowStudioManager.php)
- [`app/includes/TemplateStudioManager.php`](../app/includes/TemplateStudioManager.php)

New version sources:

- `ai-apply`
- `ai-revert`

This keeps revert durable and consistent with existing save/publish version history instead of making AI history a separate source of truth.

### 3. Service layer

Add a dedicated manager, for example:

- [`app/includes/StudioAiHistoryManager.php`](../app/includes/StudioAiHistoryManager.php)

Responsibilities:

- create/load thread summaries for a project
- enforce visibility and write permissions
- append events atomically
- persist assistant proposals with full before/after state
- apply a proposal and create an `ai-apply` version
- revert to a historical checkpoint and create an `ai-revert` version
- map event data into UI-friendly payloads

### 4. Draft ownership and thread rules

- The project draft remains shared project state.
- AI threads remain user-owned history streams.
- A new prompt always targets the current viewer's own active thread.
- Selecting another user's thread switches the UI into read-only history mode.
- Selecting an older checkpoint in your own thread also switches the composer into read-only mode unless the current live head is selected again.

### 5. Unsaved draft handling

Before the first AI prompt on a new workflow/template:

- save or auto-create the draft project
- obtain a stable `project_id`
- create the user's thread
- then persist the prompt/proposal

This avoids orphan AI history that is not attached to a project.

## Permission Model

Keep existing module permissions:

- `studio.workflows`
- `studio.ai`

Add hidden permissions/modules:

- `studio.ai.history.view_all`
  - required to view other users' threads unless the user is super-admin
- `studio.ai.history.revert_any`
  - required to revert checkpoints created by another user

Intentionally not added:

- `studio.ai.history.continue_any`
- `studio.ai.history.apply_any`

Rules:

- Super-admin can view all threads by default.
- Non-super-admin users need an explicit allow on `studio.ai.history.view_all` to inspect other users' threads.
- Non-super-admin users need an explicit allow on `studio.ai.history.revert_any` to revert another user's applied checkpoint.
- Super-admin still cannot continue another user's thread.
- Super-admin still cannot apply another user's pending proposal.
- Users without `studio.ai` cannot see the composer or AI history controls that imply AI usage.
- Users without `studio.ai.history.view_all` only receive their own thread summaries from the API.

## API Plan

Extend [`app/api/studio-workflows.php`](../app/api/studio-workflows.php) with server-owned history actions.

### New or updated endpoints

- `GET action=get-project`
  - optionally return AI history summary for the current user context

- `GET action=list-ai-history`
  - input: `type`, `projectId`
  - output:
    - visible thread tabs
    - selected thread summary
    - ordered event list
    - current viewer permissions
    - live thread ownership metadata

- `POST action=generate-ai-draft`
  - change from ephemeral request to persistent flow
  - server responsibilities:
    - ensure project exists
    - resolve or create the caller's thread
    - persist `user-prompt`
    - call `WorkflowAIManager`
    - persist `assistant-proposal` or `assistant-error`
    - return updated thread summary and event payload

- `POST action=apply-ai-proposal`
  - input: `type`, `projectId`, `threadId`, `eventId`
  - server validates:
    - proposal exists
    - proposal belongs to the caller's own live thread
    - proposal is still in `proposed` state
    - project draft still matches expected base, or caller confirms override
  - server writes:
    - project draft update
    - `ai-apply` version
    - `apply` event

- `POST action=dismiss-ai-proposal`
  - marks a proposal as dismissed without mutating the project draft
  - dismissed proposals remain visible in history with a `Not applied` or equivalent state label

- `POST action=revert-ai-checkpoint`
  - input: `type`, `projectId`, `threadId`, `eventId`
  - server validates:
    - checkpoint represents an applied proposal
    - actor is the thread owner or has `studio.ai.history.revert_any`
  - server restores the checkpoint's applied result snapshot
  - server writes:
    - project draft update
    - `ai-revert` version
    - `revert` event

### Existing endpoint changes

- `save-project`
  - append a `save` event to every existing AI thread on the project so allowed viewers see the same project milestone inside visible history

- `publish-project`
  - append a `publish` event to every existing AI thread on the project so allowed viewers see the same project milestone inside visible history

## Frontend Plan

Primary file:

- [`app/admin/studio-workflows.php`](../app/admin/studio-workflows.php)

### UI changes

- Add a thread tab strip above the AI message list.
- Each tab shows:
  - user name
  - active indicator
  - latest proposal status badge such as `Applied`, `Not applied`, or `Reverted`
- Add chat mode copy:
  - `Live thread`
  - `Viewing history`
  - `Viewing another user's thread`

### Timeline changes

- Each proposal card shows:
  - who asked
  - when it was asked
  - whether it was applied
  - who applied it
  - when it was applied
  - whether it was reverted
  - who reverted it
  - when it was reverted
- Applied checkpoints expose a clear `Go back to this point` action.
- Un-applied proposals remain inspectable but do not expose cross-user apply actions.
- Dismissed proposals remain in the timeline and are labeled as not applied.
- Save and publish events appear in the visible thread history for users allowed to inspect that thread.

### State changes

Replace browser-only AI state with hydrated server state:

- `studioState.aiThreads`
- `studioState.selectedAiThreadId`
- `studioState.aiEvents`
- `studioState.aiViewerPermissions`
- `studioState.aiReadOnlyReason`

### Interaction changes

- Loading a project fetches project data and visible AI history.
- Switching tabs fetches or hydrates the selected thread.
- `Ask AI` posts to the persistent backend flow.
- `Apply` calls `apply-ai-proposal` instead of mutating only local state.
- `Clear` becomes `Dismiss proposal` when acting on a persisted proposal.
- `Revert` calls the backend and then refreshes project plus history state.
- Pending proposals from another user's thread render in inspect-only mode with no `Apply` action.

### Diff reuse

Keep using the current diff rendering helpers for:

- raw diff
- smart diff

The main change is to source `draftBefore` and `draftAfter` from persisted event payloads rather than transient local messages.

### Read-only behavior

Disable composer when:

- selected thread is not owned by current user
- selected checkpoint is not the thread's live head
- user lacks `studio.ai`
- project is archived

## Backend File Plan

### Database and schema

- [x] Bump schema version in [`app/includes/Database.php`](../app/includes/Database.php)
- [x] Add migration for `workflow_studio_ai_threads`
- [x] Add migration for `workflow_studio_ai_events`
- [x] Add indexes for project, thread, and owner lookups
- [x] Extend workflow/template version source checks to include `ai-apply` and `ai-revert`

### Managers

- [x] Add [`app/includes/StudioAiHistoryManager.php`](../app/includes/StudioAiHistoryManager.php)
- [x] Add permission-aware thread listing
- [x] Add event persistence helpers
- [x] Add apply proposal flow
- [x] Add revert checkpoint flow
- [x] Add conflict detection based on version or normalized draft hash

### Workflow/template managers

- [x] Add helper to create version with source `ai-apply`
- [x] Add helper to create version with source `ai-revert`
- [x] Add restore/update method that can be reused by AI history actions

### API

- [x] Add `list-ai-history`
- [x] Upgrade `generate-ai-draft` to persist prompts and proposals
- [x] Add `apply-ai-proposal`
- [x] Add `dismiss-ai-proposal`
- [x] Add `revert-ai-checkpoint`
- [x] Return viewer permissions and read-only state hints
- [x] Enforce "no cross-user apply" on the server
- [x] Include save/publish events in visible thread timelines for allowed users

### Permissions and module registry

- [x] Add `studio.ai.history.view_all` to [`app/includes/SystemModuleRegistry.php`](../app/includes/SystemModuleRegistry.php)
- [x] Add `studio.ai.history.revert_any` to [`app/includes/SystemModuleRegistry.php`](../app/includes/SystemModuleRegistry.php)
- [x] Verify `PermissionManager` behavior for hidden modules

### Frontend

- [x] Add thread tab UI
- [x] Add history hydration on project load
- [x] Replace local-only apply flow with API-backed apply flow
- [x] Replace local-only revert flow with API-backed revert flow
- [x] Add read-only composer states
- [x] Add status badges for proposals and checkpoints
- [x] Add apply/revert actor and timestamp metadata to timeline cards
- [x] Keep dismissed proposals visible with a `Not applied` label
- [x] Show cross-user revert only when the viewer is allowed to revert
- [x] Pass `projectId`, `projectPayload`, and `displayPrompt` with AI requests
- [x] Keep auto-created projects selected in the current Studio session
- [x] Rewire diff modal to read persisted event snapshots
- [x] Rehydrate save/publish history from the server instead of appending browser-only timeline entries

### Audit and observability

- [x] Add audit events for prompt, proposal, apply, dismiss, and revert
- [x] Include thread/event identifiers in audit details
- [ ] Make conflict failures and permission denials visible in logs

## Frontend Status Tracker

- [x] Chat header tabs render visible user threads
- [x] Selecting another user's thread works in read-only mode
- [x] Selecting your own live thread enables the composer
- [x] Historical proposal inspection does not create a writable branch point
- [x] Historical proposals show `View Diff`
- [x] Applied checkpoints show `Go back to this point`
- [x] Timeline shows applied-by and reverted-by metadata
- [x] Dismissed proposals stay visible and read as not applied
- [x] Save and publish events are visible to allowed viewers
- [x] Cross-user revert controls only appear for allowed viewers
- [x] Proposal state badges are accurate after refresh
- [x] Page refresh preserves selected thread and history state

## Backend Status Tracker

- [x] Thread and event records persist across refresh and server restart
- [x] First AI prompt auto-creates a project when needed
- [x] Assistant proposals persist `before` and `after` drafts
- [x] Apply writes project draft plus `ai-apply` version
- [x] Revert writes project draft plus `ai-revert` version
- [x] Save and publish keep version history intact
- [x] Conflicts are rejected safely
- [x] Unauthorized cross-user thread continuation is blocked
- [x] Unauthorized cross-user proposal apply is blocked

## Testing Plan

### Automated

- [ ] Migration test for new tables and version source changes
- [ ] Permission tests for:
  - own thread access
  - super-admin cross-user visibility
  - cross-user revert permission
  - blocked cross-user prompt continuation
- [ ] API tests for:
  - prompt persistence
  - proposal persistence
  - apply flow
  - dismiss flow
  - revert flow
  - conflict handling
- [ ] Manager tests for event ordering and normalization

### Manual

- [ ] New unsaved workflow -> first AI prompt -> thread created
- [ ] Same user reloads page -> history remains
- [ ] Second user prompts on same project -> second tab appears
- [ ] Super-admin can inspect both tabs
- [ ] Super-admin cannot continue the other user's thread
- [ ] Super-admin cannot apply the other user's pending proposal
- [ ] User without extra history permission cannot see other users' tabs
- [ ] Timeline clearly shows whether each proposal was applied or not applied
- [ ] Dismissed proposals remain visible as not applied
- [ ] Save/publish events appear for users who are allowed to inspect the thread
- [ ] Cross-user revert button is absent for viewers without revert permission
- [ ] Historical diff matches the proposal that was applied
- [ ] Revert restores the expected draft and leaves an audit trail

## Delivery Batches

### Batch 1: Persistence and API foundation

Status: `Implemented`

- schema migration
- history manager
- persistent `generate-ai-draft`
- list history API

### Batch 2: Apply and revert durability

Status: `Implemented`

- API-backed apply
- API-backed revert
- API-backed dismiss
- AI-specific version sources
- conflict detection

### Batch 3: Thread tabs and history UX

Status: `Implemented`

- top tab strip
- read-only states
- persisted status badges
- diff from historical entries

### Batch 4: Permissions, audit, and hardening

Status: `Implemented`

- hidden permissions
- cross-user access enforcement
- save/publish lifecycle events in visible history
- audit coverage for prompt/proposal/apply/dismiss/revert
- manual verification pass pending

## Risks

- The current UI assumes AI messages are ephemeral and local, so converting to server-owned state may touch more client code than it first appears.
- The current project model is shared draft state, so we need strong conflict handling when reverting older checkpoints.
- Auto-creating a project before first AI prompt changes the current unsaved draft flow and must not feel surprising.
- Template and workflow managers currently duplicate some versioning logic, so AI version-source changes must stay consistent in both paths.

## Definition of Done

- AI history persists for workflow and template projects.
- The top of the Workflows Studio chat shows one tab per visible user thread.
- Super-admin can inspect all tabs.
- Users cannot continue another user's thread.
- Users cannot add prompts while viewing an old historical state.
- Every applied AI checkpoint can be diffed and reverted from persisted data.
- Apply and revert actions survive refresh and create durable Studio versions.
- Permissions and audit logs correctly reflect all AI history actions.
