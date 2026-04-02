# Doki App Manifest Norms

This file defines the current authoring norm for Doki app manifests.

It is based on what Doki actually reads today from `manifest.yaml`, plus the metadata now used in install/update UX.

## Scope

This applies to app folders discovered by Doki:

- `repo/<app-id>/manifest.yaml`
- `repo/apps/<app-id>/manifest.yaml`

For local bundled apps, Doki will fill some defaults if fields are missing. For source/marketplace apps, do not rely on those defaults. Ship a complete manifest.

## Basic rules

- The app folder name should match `id`.
- The manifest filename should be `manifest.yaml`.
- Use YAML.
- Use ASCII unless there is a real need for something else.
- Prefer camelCase keys for new metadata.
- Treat the manifest as the source of truth for app identity, trust intent, runtime needs, and update messaging.

## Fields

### Required by norm

These should be present in every app manifest:

- `id`: stable app ID, matching the folder name
- `name`: human-readable app name
- `description`: short one-line description
- `icon`: Font Awesome icon name, for example `fa-images`
- `color`: hex color, for example `"#16a34a"`
- `version`: semantic version, for example `1.2.0` (no `v` prefix)
- `enabled`: `true` or `false`
- `navigation`: at least one visible page entry
- `capabilities`: the minimum required capability list for the app to work

### Strongly recommended

- `tags`: short lowercase tags for discovery
- `optionalCapabilities`: capabilities used only for non-critical features
- `releases`: versioned release history for update previews
- `releaseNotes`: current-release fallback when `releases` is not used
- `changelogUrl`: current-release fallback changelog URL
- `breaking`: current-release fallback breaking flag

### Optional

- `runtime`: PHP extensions and system packages for a custom runtime image
- `requiredImages`: external Docker images the app depends on
- `defaultSettings`: default app settings written when no settings file exists
- `minimumTrustLevel`: explicit minimum trust level override
- `recommendedTrustLevel`: explicit recommended/default trust level override
- `core`: reserved for built-in apps only
- `permissions`: tolerated, but not authoritative for runtime access control

## Identity and UI keys

### `id`

- Keep it stable forever.
- Use lowercase kebab-case.
- It should match the app folder name.
- For local apps, Doki forces the ID from the folder name anyway, so mismatches are a bad idea.

### `name`

- Keep it short.
- Use product-style capitalization.

### `description`

- One sentence.
- Say what the app does, not implementation details.

### `icon`

- Use the bare Font Awesome name, not the style prefix.
- Good: `fa-code-compare`
- Avoid: `fas fa-code-compare`

### `color`

- Use a hex color string with `#`.

### `tags`

- Use a short list of lowercase tags.
- Prefer product/discovery tags, not internal team labels.

## Navigation

`navigation` is an array of page definitions.

Each item should include:

- `id`
- `label`
- `icon`
- `page`

Optional:

- `adminOnly`: `true` if the page should only appear for admins

Example:

```yaml
navigation:
  - id: viewer
    label: Diagram Viewer
    icon: fa-project-diagram
    page: index.php
  - id: settings
    label: Settings
    icon: fa-cog
    page: settings.php
    adminOnly: true
```

## Versioning

Use semantic versioning with bare version strings like `1.2.0`.

Doki tolerates a leading `v` when comparing versions, but the authoring norm is to omit it.

Recommended rules:

- bump `patch` for fixes and small improvements
- bump `minor` for backward-compatible features
- bump `major` for breaking changes

Important current behavior:

- Doki does **not** require a higher version number to apply an update
- Doki still compares versions for update UX
- if you leave the same version, Doki treats it as a refresh
- if you lower the version, Doki will surface it as a downgrade

So the norm is:

- always bump `version` on every published release
- only publish a lower version intentionally
- if publishing a lower version, explain it in `releaseNotes`

## Capabilities and trust

`capabilities` should declare what the app truly needs.

`optionalCapabilities` should declare capabilities used only for features that can degrade gracefully.

Doki derives trust requirements from these lists. That means accurate capability declarations matter more than manually forcing trust.

### Capability IDs

Authentication:

- `auth.read_user`
- `auth.check_roles`

Audit:

- `audit.write`
- `audit.read`

Database:

- `db.full`
- `db.app_tables`

File system:

- `fs.app_data`
- `fs.other_apps`
- `fs.core`

Configuration:

- `config.targets`
- `config.environments`
- `config.secrets`
- `config.modify`

Execution:

- `exec.commands`
- `exec.docker`
- `exec.ssh`
- `exec.any_target`

Git:

- `git.providers`
- `git.tokens`

UI:

- `ui.layout`
- `ui.inject_global`

Users:

- `users.list`
- `users.manage`

### Trust-level keys

Doki accepts explicit trust hints:

- `minimumTrustLevel`
- `recommendedTrustLevel`

Preferred values:

- `sandboxed`
- `trusted`
- `verified`

Numeric values are also tolerated:

- `3` = sandboxed
- `2` = trusted
- `1` = verified

Norm:

- prefer accurate `capabilities` over explicit trust overrides
- only set trust overrides when capability-derived trust is not enough to express intent
- do not set `core` trust in source apps

## Runtime metadata

Use `runtime` when the app needs a custom PHP runtime image.

Example:

```yaml
runtime:
  phpExtensions:
    - zip
  systemPackages:
    - libvips
```

Norm:

- only declare what is actually required
- keep ordering stable
- if runtime changes, bump the app version
- mention runtime changes in `releaseNotes`

## Required Docker images

Use `requiredImages` when the app depends on external images.

Accepted shapes:

```yaml
requiredImages:
  - image: dpokidov/imagemagick:7.1.1-15
    lifecycle: on-demand
```

Or the short form:

```yaml
requiredImages:
  - dpokidov/imagemagick:7.1.1-15
```

Supported item keys:

- `image`
- `platform`
- `lifecycle`
- `volumes`
- `env`

Norm:

- pin tags explicitly
- do not use floating tags like `latest`

## Settings

Use `defaultSettings` for initial settings only.

Example:

```yaml
defaultSettings:
  notifications:
    enabled: false
```

Norm:

- include only real defaults
- do not put secrets in the manifest

## Release metadata for update UX

These fields are now used by the update review flow.

Preferred model for source apps:

- use `releases` for version history
- keep top-level `releaseNotes`, `changelogUrl`, and `breaking` only as a fallback for the current release

That lets Doki show only the releases between the installed version and the target source version.

### `releases`

Preferred format:

```yaml
releases:
  - version: 1.3.0
    date: 2026-03-07
    notes:
      - Added bulk export
      - Improved SSH target handling
    changelogUrl: https://github.com/example/doki-apps/releases/tag/my-app-v1.3.0
    breaking: false
  - version: 1.2.0
    date: 2026-02-18
    notes:
      - Added gallery filters
```

Norm:

- one entry per released version
- include the newest releases in the manifest
- every entry must have `version`
- prefer `notes` as an array of short bullets
- prefer newest-first ordering in the file
- include `breaking: true` on any release that requires operator attention
- include `changelogUrl` on major or externally published releases

Supported release entry keys:

- `version`
- `date`
- `notes`
- `releaseNotes`
- `release_notes`
- `changelogUrl`
- `changelog_url`
- `breaking`
- `breakingChange`
- `breaking_change`

### `releaseNotes`

Preferred format:

```yaml
releaseNotes:
  - Added bulk export
  - Fixed SSH target selection
  - Reduced memory usage during diff rendering
```

A single string is tolerated, but an array is preferred.

Norm:

- 1 to 5 short bullets
- user-facing, not internal
- mention migrations or operator actions when relevant
- if `releases` is present, this should describe the current target release only

### `changelogUrl`

Example:

```yaml
changelogUrl: https://github.com/example/doki-apps/releases/tag/mermaid-viewer-v1.3.0
```

Norm:

- use an absolute `http://` or `https://` URL
- point to a stable release or changelog page
- if `releases` is present, this should be the current target release URL

### `breaking`

Example:

```yaml
breaking: true
```

Norm:

- set this to `true` for any release that requires operator action, migration, behavior reset, or config change
- when `breaking: true`, `releaseNotes` should explain the impact
- `changelogUrl` is strongly recommended for breaking releases
- if `releases` is present, this is the current target release fallback flag

### Backward-compatible aliases

Doki currently tolerates these aliases:

- `release_notes`
- `changelog_url`
- `breakingChange`
- `breaking_change`

Norm:

- prefer `releaseNotes`
- prefer `changelogUrl`
- prefer `breaking`

## Reserved or discouraged keys

### `core`

- Reserved for bundled first-party apps.
- Do not set this in source apps.

### `permissions`

- This exists in some manifests, but it is not the main enforcement mechanism.
- Actual access control comes from Doki roles, granular permissions, and capability checks in app code.

Norm:

- do not rely on `permissions` for security
- if used at all, treat it as descriptive metadata only

## Authoring checklist

Before publishing an app release:

- folder name matches `id`
- `version` bumped
- `capabilities` is accurate
- `optionalCapabilities` only contains degradable features
- `runtime` updated if runtime needs changed
- `releaseNotes` added for meaningful releases
- `breaking` set correctly
- `changelogUrl` added for major or external releases
- `navigation` pages exist
- icon uses bare `fa-...` format

## Recommended full example

```yaml
id: mermaid-viewer
name: Mermaid Viewer
description: View and render Mermaid diagrams from uploaded files or direct input
icon: fa-project-diagram
color: "#10b981"
version: 1.3.0
enabled: true

tags:
  - visualization
  - diagram
  - viewer
  - markdown

capabilities:
  - ui.layout
  - fs.app_data
  - audit.write

optionalCapabilities:
  - exec.commands

navigation:
  - id: viewer
    label: Diagram Viewer
    icon: fa-project-diagram
    page: index.php
  - id: gallery
    label: Diagram Gallery
    icon: fa-images
    page: gallery.php
  - id: settings
    label: Settings
    icon: fa-cog
    page: settings.php
    adminOnly: true

defaultSettings:
  export:
    format: svg

releaseNotes:
  - Added SVG export presets
  - Improved large-diagram rendering
  - Fixed gallery sorting

changelogUrl: https://github.com/example/doki-apps/releases/tag/mermaid-viewer-v1.3.0
breaking: false
```

Preferred release-history example:

```yaml
releases:
  - version: 1.3.0
    date: 2026-03-07
    notes:
      - Added SVG export presets
      - Improved large-diagram rendering
      - Fixed gallery sorting
    changelogUrl: https://github.com/example/doki-apps/releases/tag/mermaid-viewer-v1.3.0
    breaking: false
  - version: 1.2.0
    date: 2026-02-10
    notes:
      - Added gallery browsing
      - Improved Mermaid parser error handling
```
