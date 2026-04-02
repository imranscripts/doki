# AI Admin Implementation Status

## Current State
- Phase 1 is implemented.
- The new AI admin page is live behind the `ai.admin` permission.
- App-context blocks are now used by the current Studio AI prompt flow.
- Command-context blocks are stored, editable, and ready for a future command/template AI consumer.
- Providers now support Ollama, OpenAI, Claude, MiniMax, OpenRouter, and custom OpenAI-compatible query settings.
- The Providers tab now shows unsaved new providers as a draft row in the list for a smoother create flow.
- The Apps context tab now uses a list/detail editor and supports creating custom context blocks.
- Both Apps and Commands context tabs now use the same split editor layout with scrollable left-side lists.
- Apps and Commands now use tab-level save and reset actions with dirty counts, so admins can batch-toggle blocks from the left list and save once.

## Goals
- Add a dedicated AI admin page behind the `ai.admin` permission.
- Move AI provider management into that page as a real providers tab.
- Add `Apps` and `Commands` tabs for structured AI context management.
- Split context into logical blocks that can be enabled or disabled independently.
- Wire app-context settings into the current Studio AI prompt-building flow.

## Status
- [x] Confirm permission, nav, and Studio AI integration points
- [x] Add persistence for AI admin context blocks
- [x] Add AI admin manager/API
- [x] Build admin page with Providers / Apps / Commands tabs
- [x] Wire app context toggles into `AIContextCatalog`
- [x] Add `ai.admin` module permission and navigation entry
- [x] Verify syntax and basic integration

## Notes
- `Apps` context will control the current Studio AI app-editing flow.
- `Commands` context will be stored and managed now, ready for command/template AI flows.
- Studio now also links directly to the new AI admin page from the AI pane header when `ai.admin` is allowed.
- Claude is wired through Anthropic's `/models` and `/messages` APIs.
- MiniMax is wired through its OpenAI-compatible base URL and can use custom request params when needed.
- Custom OpenAI-compatible providers can now override chat/model paths and send extra JSON params with each query.
- Custom app context blocks are persisted alongside built-in blocks and are appended to app-focused AI prompts when enabled.

## Follow-up
- Wire the `Commands` context blocks into the first command/template AI generation flow when that feature is introduced.
