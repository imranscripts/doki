# Template Capability Refactor Plan

## Goal

Remove hardcoded template-specific behavior from Workflow AI and validation.

The system should reason from **capabilities** that are discovered from the currently available templates, not from specific template IDs like `json-field`, `json-count`, or `python-transform`.

This matters because:

- users may remove or replace templates
- installed templates can change over time
- AI and validation should adapt to what actually exists
- architecture should not drift into a pile of special cases

## Current Problem

Today we have useful tactical fixes, but they are still partially template-specific.

Examples:

- AI guidance mentions concrete template IDs
- validator hints mention concrete template IDs
- workflow quality depends on certain built-in templates being present

That is acceptable as a short-term stabilizer, but not as the long-term design.

## Desired End State

Workflow generation and validation should work like this:

1. Doki loads all currently available templates.
2. Each template advertises what it can do.
3. Doki builds a capability registry from those templates.
4. AI prompt generation references available capabilities first, and only references concrete templates that actually exist.
5. Validation and linting suggest capability-appropriate alternatives based on the registry.
6. If no suitable template exists, Doki gives a generic fallback message instead of referencing a missing template.

## Design Principles

- No hard dependency on a specific template ID in product logic.
- Templates are interchangeable implementations of capabilities.
- AI guidance should be data-driven from discovered templates.
- Validation should be capability-aware, not template-name-aware.
- The system should degrade gracefully when a capability is unavailable.

## Stopgap Already In Place

These are useful tactical improvements already present, but they are not the final architecture:

- [x] `json-field` added
- [x] `json-count` added
- [x] `python-transform` added
- [x] validator blocks raw large-payload interpolation into `shell-exec`
- [x] AI guidance pushes toward safer transforms

These should eventually become implementation details behind capability discovery, not assumptions in the code.

## Phase 1: Capability Model

- [x] Define a capability schema for templates
- [x] Decide where capability metadata lives in template YAML
- [x] Extend template loading to parse capability metadata
- [x] Keep capability metadata optional at first for rollout safety

### Proposed capability fields

Each template should be able to declare things like:

- `capabilities`
  - examples:
    - `http.fetch`
    - `json.extract`
    - `json.count`
    - `payload.transform`
    - `text.format`
    - `container.inspect`
    - `repo.analyze`
- `payloadHandling`
  - examples:
    - `scalar-only`
    - `small-text`
    - `large-text`
    - `structured-json`
- `scriptRuntime`
  - examples:
    - `sh`
    - `python`
    - `node`
- `dataFlow`
  - examples:
    - `scriptInput: command`
    - `payloadInputs: [payload1, payload2]`
    - `requestPathInput: path`
    - `requestHeadersInput: headers`

### First concrete decision

Use a single new top-level template field:

```yaml
capabilityHints:
  capabilities:
    - payload.transform
  payloadHandling:
    - structured-json
    - large-text
  scriptRuntime: python
  dataFlow:
    scriptInput: script
    payloadInputs:
      - payload1
      - payload2
```

This keeps rollout simple and avoids overcomplicating the template schema immediately.

## Phase 2: Registry Layer

- [x] Add capability extraction in `TemplateManager`
- [x] Build a normalized runtime capability registry
- [x] Expose capability lookup helpers
- [x] Expose “best available template candidates for capability X”

### Files likely involved

- `app/includes/TemplateManager.php`
- template schema files under `templates/schema`

### Registry responsibilities

- list all available templates
- list all discovered capabilities
- answer questions like:
  - “what templates support `payload.transform`?”
  - “what templates are safe for large upstream payloads?”
  - “what template is preferred for final text formatting?”

## Phase 3: AI Refactor

- [x] Remove hardcoded assumptions about template IDs from `WorkflowAIManager`
- [x] Build AI guidance from discovered capabilities
- [x] Mention concrete template IDs only if those templates are actually installed
- [x] Fall back to capability-level wording when no template is available

### Files likely involved

- `app/includes/WorkflowAIManager.php`
- `app/includes/AIAdminManager.php`

### Target behavior

Instead of:

- “prefer `python-transform`”

AI should think:

- “prefer a template that supports `payload.transform` and large structured payloads”

If such a template exists, the prompt may then name it.

If none exists, the prompt should say something like:

- “no installed transform template is currently available for large upstream payload processing”

## Phase 4: Validation Refactor

- [x] Remove hardcoded template-name-based recovery hints where possible
- [x] Make validator suggestions capability-based
- [x] Detect missing capability scenarios explicitly
- [x] Keep blocking rules generic

### Files likely involved

- `app/includes/WorkflowStudioManager.php`

### Example target behavior

Instead of:

- “use `json-field` or `json-count`”

Validation should say:

- “extract only the scalar values you need with an installed JSON-extraction or counting template”

And only append concrete names if those templates are actually available.

## Phase 5: Template Migration

- [x] Add capability metadata to the current built-in templates
- [x] Mark templates like `shell-exec` as low-preference for large payload transforms
- [x] Mark transform-style templates as preferred for payload processing
- [x] Mark final-formatting templates appropriately if we add any

### Initial built-in mappings

- `shell-exec`
  - capabilities:
    - `text.format`
    - `shell.glue`
  - dataFlow:
    - `scriptInput: command`

- `http-request` / `http-api`
  - capabilities:
    - `http.fetch`
  - dataFlow:
    - `requestPathInput: path`
    - `requestHeadersInput: headers`

- `json-field`
  - capabilities:
    - `json.extract`

- `json-count`
  - capabilities:
    - `json.count`

- `python-transform`
  - capabilities:
    - `payload.transform`
  - dataFlow:
    - `scriptInput: script`
    - `payloadInputs: payload1..payload4`

## Phase 6: Fallback Strategy

- [x] Define behavior when a workflow needs a capability that no installed template provides
- [x] Make AI explain capability gaps clearly
- [x] Make validation explain capability gaps clearly
- [x] Avoid misleading references to unavailable templates
- [x] Offer an explicit click-driven “create the missing template first” flow in Workflows Studio
- [x] Let the user return to the blocked workflow after publishing the needed template and resume workflow generation

### Expected fallback behavior

If the system detects:

- large payload transform needed
- but no transform-capable template exists

Then it should say something like:

- “This workflow needs a template capable of processing larger upstream payloads. No such template is currently available.”
- “Do you want to create the needed template first?”

Not:

- “use `python-transform`”

unless `python-transform` is actually available.

### Capability-gap resolution flow

- Validation or AI should surface the missing capability as a structured gap, not just a plain string.
- Workflows Studio should show an explicit action button the user can click to create the missing template first.
- The system must never auto-create templates without a user click.
- Clicking the action should switch Studio into template authoring mode and ask AI for a reusable template draft tailored to the missing capability plus the blocked workflow context.
- After the user reviews and publishes the template, Studio should offer a second explicit action to return to the blocked workflow and continue workflow generation with the newly available template.
- The resume step should preserve the blocked workflow draft and recent AI conversation context so the workflow can continue instead of restarting from scratch.

## Phase 7: Tests

- [x] Add unit coverage for template capability parsing
- [x] Add unit coverage for registry selection logic
- [x] Add regression tests for AI prompt generation with different installed template sets
- [x] Add validation tests for capability-aware guidance

### Must-test scenarios

- [x] Full built-in template set available
- [x] `json-field` removed
- [x] `json-count` removed
- [x] `python-transform` removed
- [x] custom replacement template installed with equivalent capabilities
- [x] only `shell-exec` available

## Phase 8: Cleanup

- [x] Remove remaining hardcoded template-ID assumptions from Workflow AI
- [x] Remove remaining hardcoded template-ID assumptions from validator hint text
- [x] Keep only product-level invariants hardcoded

### Hardcoded rules that should still exist

These are fine because they are workflow/runtime invariants, not template assumptions:

- step outputs are raw text unless exposed through extractors
- steps do not share a filesystem
- step dependencies must be explicit
- command inputs and secrets must be objects
- large upstream payloads should not be inlined into shell commands

## Progress Log

Use this section to track real implementation progress.

### 2026-04-04

- [x] Created this plan
- [x] Capability schema added
- [x] Capability registry added
- [x] AI refactor started
- [x] Validation refactor started
- [x] Initial built-in templates annotated
- [ ] Tests added
- [x] Added registry helpers in `TemplateManager` for:
  - capability lookup
  - payload-handling lookup
  - preferred/avoid use-case lookup
  - best-candidate ranking for a capability plus context
- [x] Workflow AI now:
  - includes an installed capability registry block in prompts
  - builds guidance from discovered capabilities instead of hardcoded template IDs
  - only names concrete templates when they are actually installed
  - falls back to generic capability wording when no installed template matches
- [x] Workflow validation now:
  - suggests installed templates by capability instead of hardcoded recovery names where possible
  - falls back to explicit “no installed template advertises this capability” messaging
  - keeps the runtime-safety blocking rules intact while decoupling the advice layer
- [x] Built-in template migration now covers the full current built-in set
  - all 15 built-in templates advertise `capabilityHints`
  - registry coverage now includes API validation, repo analysis, container operations, log inspection, CI inspection, PR inspection, and search/query use cases
- [x] Capability-gap fallback now:
  - returns structured `capabilityGaps` from workflow validation
  - shows explicit click-only “create template first” actions in Studio chat
  - preserves the blocked workflow state for a later resume
  - offers a post-publish “return and continue workflow” action instead of auto-creating anything
- [x] Added focused PHP regression coverage in `app/tests/capability_refactor_test.php` for:
  - template capability parsing
  - registry ranking
  - AI prompt behavior with different installed template sets
  - capability-aware validation with and without matching templates
- [x] Removed the remaining direct template-ID assumptions from workflow validation/starting defaults
  - new workflow starter steps now come from installed `shell.glue` capability candidates instead of a hardcoded `shell-exec` default
  - shell-glue validation now keys off capabilities and input metadata instead of fixed template IDs

## Open Questions

- [ ] Do we want capabilities to be fully free-form strings, or a semi-controlled vocabulary?
- [ ] Should template ranking stay heuristic, or should templates get explicit priority fields?
- [ ] Should Studio expose template capability metadata in the UI for admins?
- [ ] Do we want a “missing capability” admin diagnostic page eventually?

## Recommended Next Step

Start with **Phase 1 + Phase 2**:

1. add template capability metadata
2. build the runtime registry in `TemplateManager`
3. only then refactor AI and validation to consume that registry

That gives us the right foundation and avoids another round of tactical hardcoding.
