# Doki Logger App Plan

## Summary

`logger` is a new core Doki app for watching log files across Doki targets. It should feel like a native operational tool: admins configure which files can be watched, users open named log cards, and Doki resolves targets through the same target and environment system used by the rest of the platform.

The app must not persist raw logs. It stores only the data required to resolve targets, browse files, tail logs, search logs, and apply filters.

## Product Decisions

| Area | Decision | Implementation |
| --- | --- | --- |
| App type | Core built-in app at `app/apps/logger`. | Done |
| Admin rights | For v1, only Doki `admin` and `super-admin` users can create logger entries, groups, sources, and filters. | Basic admin gate done |
| User rights | Users with access to the logger app can view all configured logger entries and groups. No per-entry or per-group permissions in v1. | Basic user gate done |
| Storage | Logger entries, sources, groups, and filters are stored in app database tables. No YAML state for logger definitions. | Schema done |
| Log persistence | Doki does not persist raw log lines. It only keeps configuration and enough runtime state to fetch logs. | Schema stores definitions only |
| Target mode | Entries can use direct targets or environments. | Resolver done |
| Environment behavior | Environment entries resolve all log-capable targets in the selected environment. Admins must pick at least one file per log-capable target. | Resolver and validation done |
| Target refresh | Environment-backed entries store environment target keys, so changing an environment target later changes where logs are read from. | Resolver done |
| V1 target support | Support SSH/file-capable targets, local targets, and Docker-capable targets in v1. | Discovery and validation done |
| File browsing | Admins can browse the whole readable filesystem on a target, as long as directories can be listed and files can be read. | Done |
| Search | V1 supports search in currently loaded lines and remote full-file search with limits. | Entry and group done |
| Websocket | Websocket live streaming is future work. V1 uses polling tail. | Polling tail done; websocket future |
| UI routing | Prefer pretty routes for entries and groups when possible, with query-string pages as an acceptable fallback. | Not started |
| Cards | Logger home uses cards. Each card must be a real `href` so entries and groups can open in new browser tabs. | Read-only shell done |
| Group behavior | Groups are free-form and can include unrelated entries. | Admin CRUD done |
| Group display | Group pages merge lines from all included sources in one window. Each line is prefixed by the source/file name. | Done |
| Filters | Entries and groups both support filters. Group filters apply across all included logs. | V1 done |

## Core Concepts

### Logger Entry

A logger entry is the user-facing thing shown as a card. It may watch one file or many files.

Examples:

- `Production PHP Errors`
- `My App Runtime Logs`
- `Nginx Access Logs`

An entry can be direct-target backed or environment backed.

### Logger Source

A logger source is a single watched file within an entry.

Each source has a friendly source name and a path. When rendered, each log line is prefixed with the source name so merged output remains understandable.

Example rendered lines:

```text
php-error.log  2026-06-07 12:14:03 PHP Fatal error: ...
nginx.log      2026-06-07 12:14:04 GET /checkout 500
```

### Logger Group

A logger group is a free-form collection of logger entries. It helps investigate an issue when the exact source is unknown but the app, product, or incident area is known.

Examples:

- `My App`
- `Checkout Incident`
- `Production Web Tier`

Opening a group shows merged logs from every source in every included entry.

## Database Model

| Table | Purpose | Important Columns | Implementation |
| --- | --- | --- | --- |
| `app_logger_entries` | User-facing logger cards and entry pages. | `id`, `slug`, `name`, `mode`, `environment_id`, `enabled`, `created_by`, `created_at`, `updated_at` | Done |
| `app_logger_entry_sources` | Watched files for an entry. One entry can have many sources. | `id`, `entry_id`, `source_name`, `target_id`, `environment_target_key`, `path`, `target_type`, `enabled`, `display_order`, `created_at`, `updated_at` | Done |
| `app_logger_groups` | Free-form collections of entries. | `id`, `slug`, `name`, `description`, `created_by`, `created_at`, `updated_at` | Done |
| `app_logger_group_entries` | Group membership and ordering. | `group_id`, `entry_id`, `display_order` | Done |
| `app_logger_filters` | Filters for entries and groups. | `id`, `owner_type`, `owner_id`, `name`, `mode`, `pattern`, `case_sensitive`, `enabled`, `created_at`, `updated_at` | Done |

### Entry Modes

| Mode | Stored Data | Runtime Resolution | Implementation |
| --- | --- | --- | --- |
| `direct` | Sources store `target_id` and `path`. | Read the configured file from that specific target. | Resolver done |
| `environment` | Entry stores `environment_id`; sources store `environment_target_key` and `path`. | Resolve the current target mapped to that environment key, then read the source path from that target. | Resolver done |

## Implementation Batches

| Batch | Goal | What Ships | Implementation |
| --- | --- | --- | --- |
| 1 | Core app shell and schema | Add `app/apps/logger`, `manifest.yaml`, app pages, app database table creation, and basic admin/user access gates. | Done |
| 2 | Target and environment resolution | Implement log-capable target discovery for direct targets and environment-backed entries. Environment-backed entries must include at least one selected file per log-capable target. | Done |
| 3 | Admin file browser | Admin-only browser for SSH/file, local, and Docker-capable targets. It lists directories, detects files, previews readable file content, handles permission errors, and lets admins choose readable log files. | Done |
| 4 | Entry management | Admin creates, edits, disables, and deletes logger entries and their sources. Entry cards use real links and support opening in new tabs. | Done |
| 5 | Entry viewer | Entry page tails all sources for the entry, merges output, prefixes each line with the source/file name, supports pause/follow, line limits, loaded-line search, and remote full-file search. | Done |
| 6 | Group management | Admin creates groups and adds entries in a chosen order. Group cards use real links and support opening in new tabs. | Admin CRUD done |
| 7 | Group viewer | Group page merges logs from all sources in all included entries, prefixes every line by source/file name, and supports search/follow across the whole group. | Done |
| 8 | Filters v1 | Add entry-level and group-level filters. Filters can apply to currently loaded lines and remote search results. Group filters apply to all included logs. | Done |
| 9 | Polish and audit | Add audit events for browse, create, update, delete, search, and tail access. Add empty states, permission errors, and target resolution diagnostics for admins. | Done |
| 10 | Tests | Add focused tests for schema creation, target resolution, source validation, path escaping, and filter matching. | Done |

## Target Support V1

| Target Type | Expected Capability | Notes | Implementation |
| --- | --- | --- | --- |
| SSH/file target | Browse, read, tail, and search remote files over SSH. | Primary operational target type. Uses Doki target secrets and SSH connection config. | Browse, preview, tail, and search done |
| Local target | Browse, read, tail, and search local readable files. | Must guard path handling and command escaping. | Browse, preview, tail, and search done |
| Docker target | Browse, read, tail, and search files when the Docker target can execute inside a selected container or resolve a file context. | Needs a clear UI for choosing container/context when required. | Browse, preview, tail, and search done |
| Docker exec target | Browse, read, tail, and search files inside the configured container. | Likely the simplest Docker v1 path. | Browse, preview, tail, and search done |

## UI Plan

| Surface | Behavior | Implementation |
| --- | --- | --- |
| Logger home | Shows entry cards and group cards. Cards are anchors with `href`. | Shell done |
| Entry card | Shows entry name, source count, mode, enabled state, and admin-only target/transport hints. | Shell done |
| Group card | Shows group name, entry count, source count, and short description. | Shell done |
| Entry page | Shows merged logs for one entry. Every line is prefixed by source/file name. | Tail/search/filter chips and source health done |
| Group page | Shows merged logs for all included entries. Every line is prefixed by source/file name. | Tail/search/filter chips and source health done |
| Admin create/edit | Lets admins choose direct target or environment, browse and preview files, name sources, name entries, create groups, and save. | Done |
| Admin filters | Lets admins create, edit, disable, and delete entry/group filters. | Done |
| Admin diagnostics | Shows target resolution details and transport pills such as `ssh`, `local`, or `docker`. | Resolver diagnostics and source check action done |
| User view | Users see only configured cards and log viewers. They do not browse targets or see sensitive target details. | Cards and entry/group viewers done |

## Search And Tail Behavior

| Feature | Behavior | Implementation |
| --- | --- | --- |
| Initial load | Fetch the latest N lines from each source. | Entry and group done |
| Follow mode | Poll for new lines. Websocket is future work. | Entry and group done |
| Loaded-line search | Search the lines already loaded in the browser, including saved filter chips. | Entry and group done |
| Remote full-file search | Run bounded search on target files with result limits and timeout. Saved filters can refine search results or run as the search source. | Entry and group done |
| Merged ordering | Best effort order by detected timestamp when possible, otherwise by fetch order. | Entry and group done |
| Prefixing | Prefix every rendered line with source/file name, especially in entry and group merged views. | Entry and group done |

## Filter Plan

| Filter Scope | Behavior | Implementation |
| --- | --- | --- |
| Entry filter | Applies to all sources in one logger entry. | Done |
| Group filter | Applies to every included source across all group entries. | Done |
| Contains all terms | Match only if all terms are present. | Done |
| Contains any term | Match if any term is present. | Done |
| Regex | Optional v1 if it can be made safe with timeouts and length limits. | Future |
| Case sensitivity | Toggle per filter. | Done |

## API Shape

| Endpoint | Purpose | Access | Implementation |
| --- | --- | --- | --- |
| `GET /apps/logger/` | Card index for entries and groups. | User | Done |
| `GET /apps/logger/entry/{slug}` | Entry viewer, preferred pretty route. | User | Future |
| `GET /apps/logger/entry.php?id=...` | Entry viewer fallback route. | User | Done |
| `GET /apps/logger/group/{slug}` | Group viewer, preferred pretty route. | User | Future |
| `GET /apps/logger/group.php?id=...` | Group viewer fallback route. | User | Done |
| `GET /api/logger.php?action=entries` | List entries. | User | Done |
| `POST /api/logger.php?action=entry` | Create or update entry. | Admin | Done |
| `DELETE /api/logger.php?action=entry&id=...` | Delete or disable entry. | Admin | Done |
| `GET /api/logger.php?action=groups` | List groups. | User | Done |
| `POST /api/logger.php?action=group` | Create or update group. | Admin | Done |
| `DELETE /api/logger.php?action=group&id=...` | Delete group. | Admin | Done |
| `GET /api/logger.php?action=filters` | List all filters or filters for one owner. | User | Done |
| `GET /api/logger.php?action=filter&id=...` | Fetch one filter. | User | Done |
| `POST /api/logger.php?action=filter` | Create or update an entry/group filter. | Admin | Done |
| `DELETE /api/logger.php?action=filter&id=...` | Delete a filter. | Admin | Done |
| `GET /api/logger.php?action=browse` | Browse target filesystem. | Admin | Done |
| `GET /api/logger.php?action=preview` | Preview readable file content from a target before adding it as a source. | Admin | Done |
| `GET /api/logger.php?action=tail` | Fetch latest lines for an entry. | User | Entry done |
| `GET /api/logger.php?action=search` | Search remote entry source files with result limits and timeout. | User | Entry done |
| `GET /api/logger.php?action=group-tail` | Fetch latest merged lines for a group. | User | Done |
| `GET /api/logger.php?action=group-search` | Search remote source files across every entry in a group. | User | Done |
| `GET /api/logger.php?action=target-context` | List log-capable target and environment resolution context. | Admin | Done |
| `POST /api/logger.php?action=validate-sources` | Validate direct or environment source coverage before save. | Admin | Done |

## Audit Coverage

| Action | Behavior | Implementation |
| --- | --- | --- |
| Create/update/delete entries | Audit admin entry mutations with mode and source counts. | Done |
| Create/update/delete groups | Audit admin group mutations with entry counts. | Done |
| Create/update/delete filters | Audit filter mutations without storing raw filter details beyond pattern length. | Done |
| Browse/preview files | Audit admin filesystem browse and preview attempts. | Done |
| Tail access | Audit entry and group tail refreshes with line/source/error counts. Background follow polls are marked as `poll=1` to avoid audit flooding. | Done |
| Search access | Audit entry and group searches with query length, limit, case flag, and filter count. | Done |
| Source validation | Audit admin source coverage checks with source/error counts. | Done |

## Test Coverage

| Test Area | Behavior Covered | Implementation |
| --- | --- | --- |
| Schema creation | Logger app tables are created in an isolated app database and CRUD uses those tables. | Done in `app/tests/logger_test.php` |
| Target resolution | Log-capable direct targets, environment contexts, missing/non-log targets, and environment retargeting are covered. | Done |
| Source validation | Environment-backed entries must include at least one source per log-capable environment target. | Done |
| Filter matching | Contains-all, contains-any, case sensitivity, term parsing, and entry/source/path context matching are covered. | Done |
| Path/query safety | Local tail/search handle shell-sensitive file paths and literal queries, and remote command args are shell-escaped. | Done |

## Future Doki Improvement Block

This block is intentionally future work because it needs Doki runtime improvements beyond the logger app.

| Future Capability | Behavior | Implementation |
| --- | --- | --- |
| Websocket log streaming | Move live log sessions to the Go orchestrator and stream through websocket when available. | Future |
| Polling fallback | Keep polling tail as fallback when websocket is unavailable. | Future |
| Runtime error reporter | Filters can create error reporter events linked to logger entries, groups, and sources. | Future |
| Notification policy | Admin can choose notify for each error, daily digest, weekly digest, or similar schedules. | Future |
| Dedupe and throttle | Reporter can fingerprint repeated errors and throttle noisy notifications. | Future |
| Example filter syntax | Support expressions such as `contains: 500 + php:fatal`. | Future |
| Notification channels | Email first, with room for other channels later. | Future |

## Open Implementation Notes

| Topic | Note | Implementation |
| --- | --- | --- |
| Pretty routes | Doki app routing may require fallback query-string routes first. Pretty routes are preferred but not a blocker. | Fallback routes done |
| Docker browsing | Docker target support needs a precise UX for selecting a container or file context when the target is not already a single `docker-exec` target. | Container virtual root done |
| Timestamp merging | True chronological merging needs timestamp detection. V1 can fall back to source fetch order when timestamps are unavailable. | Best-effort timestamp parsing done |
| Full-file search safety | Remote search must have timeouts, output limits, escaped inputs, and clear errors. | Done for entry and group search |
| Whole filesystem browsing | Admins can browse any readable path, but every browse/read error should be shown clearly and audited. | Browse done |
