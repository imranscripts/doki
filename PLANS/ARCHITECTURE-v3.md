# Doki Architecture v3 - Clean Separation

## The Problem with v2

Still mixing concerns:
- Templates folder contained infrastructure config (targets, environments)
- Tool definitions were tangled with deployment specifics

**Templates should know NOTHING about your infrastructure.**

---

## v3: Four Separate Concerns

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐          │
│  │    TEMPLATES    │    │ INFRASTRUCTURE  │    │    COMMANDS     │          │
│  │   (Shareable)   │    │   (Private)     │    │  (Your config)  │          │
│  ├─────────────────┤    ├─────────────────┤    ├─────────────────┤          │
│  │                 │    │                 │    │                 │          │
│  │ • docker-mgmt   │    │ • targets.yaml  │    │ • my-commands/  │          │
│  │ • elasticsearch │    │ • envs.yaml     │    │                 │          │
│  │ • log-viewer    │    │ • secrets.yaml  │    │ "restart X on   │          │
│  │ • http-request  │    │                 │    │  target Y"      │          │
│  │ • shell-exec    │    │ YOUR servers    │    │                 │          │
│  │                 │    │ YOUR creds      │    │ Ties templates  │          │
│  │ WHAT a tool     │    │ YOUR networks   │    │ to infra        │          │
│  │ does            │    │                 │    │                 │          │
│  │ Could be        │    │ NEVER in git    │    │ Your specific   │          │
│  │ open source     │    │ (or encrypted)  │    │ use cases       │          │
│  │                 │    │                 │    │                 │          │
│  └─────────────────┘    └─────────────────┘    └─────────────────┘          │
│           │                     │                      │                     │
│           └──────────┬──────────┴──────────────────────┘                     │
│                      │                                                       │
│                      ▼                                                       │
│            ┌─────────────────┐                                               │
│            │  ORCHESTRATOR   │                                               │
│            │                 │                                               │
│            │ Loads all three │                                               │
│            │ Resolves refs   │                                               │
│            │ Executes        │                                               │
│            └─────────────────┘                                               │
│                                                                              │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## Directory Structure

```
doki/
├── templates/                    # SHAREABLE - from git repo or local
│   ├── docker-management.template.yaml
│   ├── elasticsearch.template.yaml
│   ├── log-viewer.template.yaml
│   ├── http-request.template.yaml
│   └── shell-exec.template.yaml
│
├── infrastructure/               # PRIVATE - your deployment
│   ├── targets.yaml              # Your servers, clusters, endpoints
│   ├── environments.yaml         # Your dev/staging/prod definitions
│   ├── secrets.yaml              # Credentials (encrypted, or use Vault)
│   └── .gitignore                # Ignore secrets.yaml
│
├── commands/                     # YOUR CONFIG - your specific commands
│   ├── docker-commands.yaml
│   └── elasticsearch-commands.yaml
│
├── apps/                         # STATEFUL TOOLS (own UI, storage)
│   └── playwright/               # Playwright test runner app
│       ├── config/               # Admin-editable settings
│       ├── projects/             # Test projects (git sync or upload)
│       └── data/                 # Reports, videos, history
│
└── doki.yaml                     # Main config
```

---

## What Each Part Contains

### 1. Templates (Public/Shareable)

```yaml
# templates/docker-management.template.yaml
id: docker-management
name: Docker Management
description: Manage Docker containers

# Container image this template runs in
runtime:
  image: docker:24-cli           # Minimal Docker CLI image
  
# WHAT inputs this tool needs (abstract)
inputs:
  - name: action
    type: select
    options: [ps, start, stop, restart, logs]
  - name: container
    type: string
    required: false

# WHAT the tool does (script runs inside the container)
execution:
  script: |
    docker {{action}} {{container}}

# WHAT outputs it produces
output:
  type: text

# NO servers, NO credentials, NO hosts
# This template works ANYWHERE - orchestrator injects target details
```

### 2. Infrastructure (Private/Deployment-Specific)

```yaml
# infrastructure/targets.yaml
targets:
  - id: prod-docker
    type: docker-host
    # ACTUAL connection details
    connection:
      method: ssh
      host: 10.0.1.50
      user: deploy
      keyRef: ssh-key-prod  # Reference to secrets, not actual key
      
  - id: staging-docker
    type: docker-host
    connection:
      method: ssh  
      host: 10.0.2.50
      user: deploy
      keyRef: ssh-key-staging
```

```yaml
# infrastructure/environments.yaml
environments:
  production:
    targets:
      docker: prod-docker
      elasticsearch: prod-es
    policies:
      requiresApproval: true
      
  staging:
    targets:
      docker: staging-docker
      elasticsearch: staging-es
```

### 3. Secrets (In Infrastructure, Encrypted or External)

```yaml
# infrastructure/secrets.yaml (GITIGNORED, encrypted at rest)
secrets:
  ssh-key-prod: |
    -----BEGIN RSA PRIVATE KEY-----
    ...
  ssh-key-staging: |
    -----BEGIN RSA PRIVATE KEY-----
    ...
  es-prod-password: "actual-password-here"
  github-token: "ghp_xxxxxxxxxxxx"  # For git sync
```

Or better: **use external secrets manager**:

```yaml
# infrastructure/secrets.yaml (safe to commit if using external provider)
secretsProvider:
  type: vault  # or: aws-secrets, azure-keyvault, env
  address: https://vault.company.com
  path: secret/data/doki
```

### 4. Commands (Your Specific Use Cases)

```yaml
# commands/docker-commands.yaml
commands:
  - id: restart-airflow
    name: Restart Airflow Scheduler
    
    # References template by ID (from templates/)
    template: docker-management
    
    # References target type (resolved from infrastructure/)
    target:
      fromEnvironment: docker  # Uses env.targets.docker
      
    # Pre-filled inputs
    defaults:
      action: restart
      container: mirakl-airflow-scheduler-1
      
    access:
      roles: [ops, dev]
```

---

## The Flow

```
User: "I want to restart Airflow in Production"

1. Load template:      templates/docker-management.yaml
2. Load environment:   infrastructure/environments.yaml → production
3. Resolve target:     production.targets.docker → "prod-docker"
4. Load target:        infrastructure/targets.yaml → prod-docker details
5. Load secrets:       secrets/ or Vault → SSH key for prod
6. Merge inputs:       command.defaults + user input
7. Execute:            SSH to 10.0.1.50, run "docker restart ..."
```

---

## Why This Matters

| Concern | Where it lives | In git? | Shareable? |
|---------|---------------|---------|------------|
| Tool definitions | `templates/` (local or git) | ✅ Yes | ✅ Public |
| Your servers | `infrastructure/targets.yaml` | ⚠️ Maybe | ❌ Private |
| Your environments | `infrastructure/environments.yaml` | ✅ Yes | ❌ Private |
| Credentials | `infrastructure/secrets.yaml` (encrypted) | ❌ Never | ❌ Private |
| Your commands | `commands/` | ✅ Yes | ❌ Private |
| Apps (Playwright) | `apps/` | ✅ Config, ❌ Data | ❌ Private |

**Templates become a library.** You could:
- Download templates from a public registry
- Share templates between teams
- Version templates independently
- Use community templates

**Infrastructure stays private.** Your servers, your credentials, your network topology - never mixed with tool definitions.

---

## Admin Editing & Permissions

### Role Hierarchy

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                             │
│  SUPER-ADMIN          ADMIN                    USER                         │
│  ───────────          ─────                    ────                         │
│                                                                             │
│  • Edit targets       • Edit targets           • Execute commands           │
│  • Edit environments  • Edit environments      • View results               │
│  • Edit secrets       • Edit secrets           • View reports               │
│  • Edit commands      • Edit commands                                       │
│  • Manage users       • View audit logs                                     │
│  • View audit logs                                                          │
│  • System config                                                            │
│                                                                             │
│  ❌ NO template editing (templates are dev-maintained, read-only)           │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### What's Editable via UI

| Resource | Super-Admin | Admin | User | Notes |
|----------|-------------|-------|------|-------|
| `templates/*.yaml` | ❌ Read-only | ❌ Read-only | ❌ Hidden | Maintained by devs via git |
| `infrastructure/targets.yaml` | ✅ Edit | ✅ Edit | ❌ Hidden | Server/endpoint definitions |
| `infrastructure/environments.yaml` | ✅ Edit | ✅ Edit | ❌ Hidden | Target groupings |
| `infrastructure/secrets.yaml` | ✅ Edit | ✅ Edit | ❌ Hidden | Credentials (secured UI) |
| `commands/*.yaml` | ✅ Edit | ✅ Edit | ❌ Hidden | Command definitions |
| User management | ✅ | ❌ | ❌ | Create/edit/delete users |
| Audit logs | ✅ View | ✅ View | ❌ | Who did what, when |

### Secrets UI Security

Secrets are sensitive but need to be editable. Security measures:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ SECRETS EDITOR                                                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. MASKED DISPLAY                                                          │
│     - Values shown as ●●●●●●●● until explicitly revealed                    │
│     - "Show" button requires re-authentication                              │
│                                                                             │
│  2. AUDIT ON ACCESS                                                         │
│     - Log every view of a secret value                                      │
│     - Log every edit of a secret                                            │
│                                                                             │
│  3. CONFIRMATION                                                            │
│     - "Save" requires password re-entry                                     │
│     - Show diff of changes before save                                      │
│                                                                             │
│  4. ENCRYPTION AT REST                                                      │
│     - secrets.yaml encrypted on disk                                        │
│     - Decrypted only when needed                                            │
│                                                                             │
│  5. SESSION TIMEOUT                                                         │
│     - Secrets page auto-locks after 5 minutes of inactivity                 │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Backup & Versioning

Every edit creates a timestamped backup:

```
infrastructure/
├── targets.yaml                      # Current version
├── .history/
│   ├── targets.yaml.2024-01-15T10-30-00.backup
│   ├── targets.yaml.2024-01-14T15-22-33.backup
│   └── targets.yaml.2024-01-10T09-00-00.backup
├── environments.yaml
├── .history/
│   └── environments.yaml.2024-01-15T10-30-00.backup
└── secrets.yaml                      # Encrypted
```

Backup metadata:
```yaml
# .history/targets.yaml.2024-01-15T10-30-00.meta
editedBy: admin@company.com
editedAt: 2024-01-15T10:30:00Z
reason: "Added new staging server"  # Optional
previousHash: abc123...
newHash: def456...
```

### Audit Log

All actions are logged:

```yaml
# audit/audit.log (append-only, or sent to external logging)

- timestamp: 2024-01-15T10:30:00Z
  action: edit
  resource: infrastructure/targets.yaml
  user: admin@company.com
  ip: 192.168.1.100
  details:
    added: ["staging-server-2"]
    modified: []
    removed: []

- timestamp: 2024-01-15T10:35:00Z
  action: execute
  resource: commands/restart-airflow
  user: dev@company.com
  ip: 192.168.1.101
  environment: production
  target: prod-docker
  result: success
  duration: 2.3s

- timestamp: 2024-01-15T10:40:00Z
  action: view_secret
  resource: infrastructure/secrets.yaml
  user: admin@company.com
  ip: 192.168.1.100
  secretKey: ssh-key-prod
```

### Validation on Save

Basic validation only (fast, catches obvious errors):

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ VALIDATION CHECKS                                                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ✅ YAML syntax valid                                                       │
│  ✅ Required fields present (id, name, type for targets)                    │
│  ✅ No duplicate IDs                                                        │
│  ✅ Known field names (warn on unknown fields, don't block)                 │
│                                                                             │
│  ❌ NOT checking: Do referenced targets exist?                              │
│  ❌ NOT checking: Are secrets actually valid credentials?                   │
│  ❌ NOT checking: Can we connect to this server?                            │
│                                                                             │
│  (Deep validation is expensive and can be done separately via health check) │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### UI Editing Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                             │
│  1. NAVIGATE                                                                │
│     Admin clicks "Settings" → "Infrastructure" → "Targets"                  │
│                                                                             │
│  2. LIST VIEW                                                               │
│     Shows all targets as cards/table with quick actions                     │
│     [+ Add Target]  [Edit]  [Delete]                                        │
│                                                                             │
│  3. EDIT VIEW                                                               │
│     - Form-based editor (friendly) OR                                       │
│     - Raw YAML editor (power users, with syntax highlighting)               │
│     - Toggle between views                                                  │
│                                                                             │
│  4. VALIDATE                                                                │
│     On blur or on save attempt, show validation errors inline               │
│                                                                             │
│  5. SAVE                                                                    │
│     - Show diff: "You are about to change X, Y, Z"                          │
│     - [Cancel] [Save]                                                       │
│     - Creates backup automatically                                          │
│     - Writes audit log                                                      │
│                                                                             │
│  6. CONFIRM                                                                 │
│     - Toast notification: "Targets saved successfully"                      │
│     - Or error message if validation fails                                  │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Authentication System (To Be Implemented)

Current auth is basic (hardcoded users in PHP). Needs upgrade:

### User Model

```yaml
users:
  - id: uuid
    email: admin@company.com
    name: John Admin
    role: super-admin  # super-admin | admin | user
    passwordHash: bcrypt...
    createdAt: 2024-01-01T00:00:00Z
    lastLogin: 2024-01-15T10:00:00Z
    active: true
    
  - id: uuid
    email: dev@company.com
    name: Jane Developer
    role: user
    # ...
```

### Auth Features (Future)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ AUTHENTICATION ROADMAP                                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  PHASE 1 (MVP)                                                              │
│  • User/password stored in DB (hashed)                                      │
│  • Session-based auth                                                       │
│  • Role-based access (super-admin, admin, user)                             │
│                                                                             │
│  PHASE 2                                                                    │
│  • SSO integration (SAML, OIDC)                                             │
│  • 2FA support                                                              │
│  • API tokens for automation                                                │
│                                                                             │
│  PHASE 3                                                                    │
│  • LDAP/Active Directory integration                                        │
│  • Fine-grained permissions (per-command, per-environment)                  │
│  • Temporary elevated access                                                │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Apps System (Stateful Tools)

Some tools don't fit the simple template model. They need:
- Persistent storage (reports, history)
- Their own UI (viewers, dashboards)
- Their own data model
- Lifecycle management (retention, cleanup)

**Playwright is the first example** - it's more like a mini web app than a command.

### Apps Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                             │
│  ┌───────────────────────────────┐    ┌───────────────────────────────┐    │
│  │         TEMPLATES             │    │           APPS                │    │
│  │       (Stateless)             │    │        (Stateful)             │    │
│  ├───────────────────────────────┤    ├───────────────────────────────┤    │
│  │                               │    │                               │    │
│  │  • Docker management          │    │  • Playwright                 │    │
│  │  • Elasticsearch queries      │    │    - Test runner              │    │
│  │  • Log viewer                 │    │    - Report storage           │    │
│  │  • HTTP requests              │    │    - Report viewer            │    │
│  │  • Shell commands             │    │    - History/trends           │    │
│  │                               │    │                               │    │
│  │  Simple: run → output         │    │  • (Future) Scheduled Jobs    │    │
│  │  No persistence               │    │  • (Future) Metrics Dashboard │    │
│  │  Generic execution engine     │    │                               │    │
│  │                               │    │  Has own code/UI/storage      │    │
│  │  Defined in YAML              │    │  Has own config (editable!)   │    │
│  │                               │    │                               │    │
│  └───────────────────────────────┘    └───────────────────────────────┘    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### App Directory Structure

```
apps/
├── playwright/
│   ├── code/                     # READ-ONLY (dev-maintained)
│   │   ├── runner.ts             # Test execution logic
│   │   ├── components/           # UI components
│   │   └── services/             # Business logic
│   │
│   ├── config/                   # ADMIN-EDITABLE via UI
│   │   ├── settings.yaml         # App settings
│   │   └── projects.yaml         # Test projects configuration
│   │
│   └── data/                     # Runtime data (managed by app)
│       ├── reports/              # Stored HTML reports
│       ├── videos/               # Test recordings
│       └── runs.db               # Run history
│
└── (future apps)/
```

### App Config: Admin-Editable

```yaml
# apps/playwright/config/settings.yaml
# ─────────────────────────────────────
# EDITABLE BY: super-admin, admin
# ─────────────────────────────────────

storage:
  reportsPath: ./data/reports/
  videosPath: ./data/videos/
  retention:
    reports: 30d      # Keep reports for 30 days
    videos: 7d        # Keep videos for 7 days
  maxStorageGB: 10    # Auto-cleanup when exceeded

defaults:
  browser: chromium
  workers: 2
  retries: 1
  timeout: 60000      # ms

notifications:
  onFailure:
    enabled: true
    channels: [slack]
  onSuccess:
    enabled: false

ui:
  showVideoPreview: true
  defaultPageSize: 20
```

```yaml
# apps/playwright/config/settings.yaml (continued)
# ─────────────────────────────────────
# SHARED GIT AUTHENTICATION
# ─────────────────────────────────────
# All projects can use these shared git credentials
# No need to configure auth per project

gitProviders:
  - id: github-company
    name: Company GitHub
    type: github
    tokenRef: github-token          # Secret reference
    
  - id: gitlab-internal
    name: Internal GitLab
    type: gitlab
    url: https://gitlab.company.com
    tokenRef: gitlab-token

# Test target environments (shared across projects)
testEnvironments:
  - id: staging
    name: Staging
    baseUrl: https://staging.pharmalia.fr
    credentials:
      userRef: pw-staging-user
      passRef: pw-staging-pass
      
  - id: production
    name: Production
    baseUrl: https://www.pharmalia.fr
    credentials:
      userRef: pw-prod-user
      passRef: pw-prod-pass
    restricted: true  # Requires approval to run
```

### App Permissions Matrix

| Resource | Super-Admin | Admin | User |
|----------|-------------|-------|------|
| App code (`apps/*/code/`) | ❌ Read-only | ❌ Read-only | ❌ Hidden |
| App settings (`apps/*/config/settings.yaml`) | ✅ Edit | ✅ Edit | ❌ View only |
| App projects (`apps/*/config/projects.yaml`) | ✅ Edit | ✅ Edit | ❌ View only |
| App data (`apps/*/data/`) | ✅ View/Delete | ✅ View/Delete | ✅ View |
| Run tests | ✅ | ✅ | ✅ (if allowed) |
| View reports | ✅ | ✅ | ✅ |
| Delete reports | ✅ | ✅ | ❌ |

### App Config UI

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ PLAYWRIGHT SETTINGS                                            [Admin View] │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ STORAGE                                                              │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │ Report retention:    [30] days                                       │   │
│  │ Video retention:     [7]  days                                       │   │
│  │ Max storage:         [10] GB                                         │   │
│  │ Current usage:       2.3 GB (23%)  [Clean up old reports]            │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ TEST DEFAULTS                                                        │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │ Browser:             [Chromium ▼]                                    │   │
│  │ Parallel workers:    [2]                                             │   │
│  │ Retries on failure:  [1]                                             │   │
│  │ Timeout:             [60] seconds                                    │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ NOTIFICATIONS                                                        │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │ [✓] Notify on test failure      Channel: [Slack ▼]                   │   │
│  │ [ ] Notify on test success      Channel: [──── ▼]                    │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│                                                    [Cancel]  [Save Changes] │
└─────────────────────────────────────────────────────────────────────────────┘
```

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ PLAYWRIGHT PROJECTS                                            [Admin View] │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  [+ Add Project]                                                            │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ 📁 OCP Portail Marketplace                              [Edit] [Del] │   │
│  │    Path: ./playwright/ocp-portail-marketplace                        │   │
│  │    Tests: 17 files                                                   │   │
│  │    Environments: staging, production (restricted)                    │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ 📁 API Integration Tests                                [Edit] [Del] │   │
│  │    Path: ./playwright/api-tests                                      │   │
│  │    Tests: 8 files                                                    │   │
│  │    Environments: staging                                             │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Summary: What's Editable

| Layer | Read-Only (Dev) | Editable (Admin) |
|-------|-----------------|------------------|
| **Core** | Templates | Infrastructure, Commands |
| **Apps** | App code (runner, UI) | App config + App data (tests, reports) |

Same pattern everywhere: **Code = devs, Config/Data = admins**

---

## Playwright: Test Management

Tests and all their dependencies are **DATA**, not code. Admins manage them.

### What Admins Can Upload/Manage

```
apps/playwright/projects/
├── ocp-marketplace/                  # A test project
│   ├── tests/                        # Test files (.test.ts)
│   │   ├── home.test.ts
│   │   ├── checkout.test.ts
│   │   └── ...
│   ├── helpers/                      # Helper modules
│   │   ├── auth.ts
│   │   ├── cart.ts
│   │   └── ...
│   ├── config/                       # Environment configs
│   │   ├── staging.env
│   │   ├── production.env
│   │   └── playwright.config.ts
│   ├── fixtures/                     # Test data
│   │   └── users.json
│   └── project.yaml                  # Project metadata (admin-editable)
│
└── api-tests/                        # Another project
    └── ...
```

**Everything in a project folder is admin-managed data.**

### Two Ways to Add/Update Tests

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                             │
│   OPTION 1: GIT SYNC                    OPTION 2: LOCAL UPLOAD              │
│   ──────────────────                    ────────────────────                │
│                                                                             │
│   Admin configures git repo             Admin uploads files directly        │
│   Doki pulls on demand or schedule      Via web UI                          │
│                                                                             │
│   ┌─────────────────────────┐          ┌─────────────────────────┐         │
│   │ Repo: github.com/...    │          │ [📁 Drop files here]    │         │
│   │ Branch: main            │          │                         │         │
│   │ Path: /tests            │          │ Or click to browse      │         │
│   │                         │          │                         │         │
│   │ [Sync Now] [Auto-sync]  │          │ [Upload]                │         │
│   └─────────────────────────┘          └─────────────────────────┘         │
│                                                                             │
│   Good for:                            Good for:                            │
│   • CI/CD integration                  • Quick one-off uploads              │
│   • Dev team manages tests             • No git needed                      │
│   • Version history in git             • Admin full control                 │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Project Configuration (Simplified)

Each project has a simple config. Git auth and test environments are shared from app settings.

```yaml
# apps/playwright/projects/ocp-marketplace/project.yaml

id: ocp-marketplace
name: OCP Marketplace Tests
description: "E2E tests for the marketplace portal"

# Source: Git (uses shared git provider) or Local upload
source:
  type: git                           # or: local
  gitProvider: github-company         # References shared provider from settings.yaml
  repo: company/marketplace-tests     # Just org/repo, not full URL
  branch: main
  path: /                             # Subfolder in repo (optional)
  sync:
    auto: true                        # Pull before each run?
    schedule: "0 6 * * *"             # Or cron schedule

# Which test environments this project can target
# References shared environments from settings.yaml
allowedEnvironments: [staging, production]

# Project-specific env file (optional, merged with environment config)
envFile: ./config/project.env

# Override defaults for this project (optional)
defaults:
  browser: chromium
  workers: 2
  retries: 1
```

```yaml
# Another project - even simpler with local upload

# apps/playwright/projects/quick-tests/project.yaml

id: quick-tests
name: Quick Smoke Tests
description: "Fast smoke tests uploaded directly"

source:
  type: local                         # Files managed via UI upload

allowedEnvironments: [staging]        # Only staging, not production
```

### Multiple Projects, Shared Auth

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                             │
│  SHARED (in settings.yaml)              PER-PROJECT (in project.yaml)       │
│  ─────────────────────────              ─────────────────────────────       │
│                                                                             │
│  Git Providers:                         Just reference by ID:               │
│    • github-company (token: xxx)          gitProvider: github-company       │
│    • gitlab-internal (token: yyy)         repo: company/my-tests            │
│                                                                             │
│  Test Environments:                     Just reference by ID:               │
│    • staging (url, creds)                 allowedEnvironments: [staging]    │
│    • production (url, creds)                                                │
│                                                                             │
│  Benefits:                                                                  │
│    • Add 10 projects, configure git auth ONCE                               │
│    • Change staging URL in ONE place                                        │
│    • Rotate credentials without touching projects                           │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### File Upload UI

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ PROJECT: OCP Marketplace Tests                              [Admin View]    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Source: [Local Upload ▼]  (or Git Repository)                              │
│                                                                             │
│  ┌─ FILES ──────────────────────────────────────────────────────────────┐   │
│  │                                                                      │   │
│  │  📁 tests/                                          [Upload] [New]   │   │
│  │    ├── 📄 home.test.ts                    12 KB    [Edit] [Delete]   │   │
│  │    ├── 📄 checkout.test.ts                8 KB     [Edit] [Delete]   │   │
│  │    ├── 📄 cart.test.ts                    6 KB     [Edit] [Delete]   │   │
│  │    └── 📄 login.test.ts                   4 KB     [Edit] [Delete]   │   │
│  │                                                                      │   │
│  │  📁 helpers/                                        [Upload] [New]   │   │
│  │    ├── 📄 auth.ts                         3 KB     [Edit] [Delete]   │   │
│  │    └── 📄 cart.ts                         2 KB     [Edit] [Delete]   │   │
│  │                                                                      │   │
│  │  📁 config/                                         [Upload] [New]   │   │
│  │    ├── 📄 staging.env                     1 KB     [Edit] [Delete]   │   │
│  │    ├── 📄 production.env                  1 KB     [Edit] [Delete]   │   │
│  │    └── 📄 playwright.config.ts            2 KB     [Edit] [Delete]   │   │
│  │                                                                      │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─ BULK UPLOAD ────────────────────────────────────────────────────────┐   │
│  │                                                                      │   │
│  │    📦 Drop ZIP file here to upload entire project                    │   │
│  │       or [Browse...]                                                 │   │
│  │                                                                      │   │
│  │    ⚠️ This will replace all existing files                           │   │
│  │                                                                      │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### File Editor (For Individual Files)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ EDIT: tests/checkout.test.ts                                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  1 │ import { test, expect } from '@playwright/test';                │   │
│  │  2 │ import { login } from '../helpers/auth';                        │   │
│  │  3 │ import { addToCart } from '../helpers/cart';                    │   │
│  │  4 │                                                                 │   │
│  │  5 │ test('user can complete checkout', async ({ page }) => {        │   │
│  │  6 │   await login(page);                                            │   │
│  │  7 │   await addToCart(page, 'product-123');                         │   │
│  │  8 │   await page.click('[data-testid="checkout"]');                 │   │
│  │  9 │   // ...                                                        │   │
│  │ 10 │ });                                                             │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  Last edited: 2024-01-15 10:30 by admin@company.com                         │
│                                                                             │
│                                              [Cancel]  [Save]               │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Git Sync UI

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ PROJECT: OCP Marketplace Tests                              [Admin View]    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Source: [Git Repository ▼]                                                 │
│                                                                             │
│  ┌─ GIT CONFIGURATION ──────────────────────────────────────────────────┐   │
│  │                                                                      │   │
│  │  Provider:    [Company GitHub ▼]  (configured in Playwright Settings)│   │
│  │  Repository:  [company/marketplace-tests    ]                        │   │
│  │  Branch:      [main ▼]                                               │   │
│  │  Path:        [/                          ] (subfolder, optional)    │   │
│  │                                                                      │   │
│  │  [✓] Auto-sync before each test run                                  │   │
│  │  [ ] Scheduled sync: [0 6 * * *] (cron)                              │   │
│  │                                                                      │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─ SYNC STATUS ────────────────────────────────────────────────────────┐   │
│  │                                                                      │   │
│  │  Last sync: 2024-01-15 10:30:00                                      │   │
│  │  Commit: abc1234 - "Added new checkout tests"                        │   │
│  │  Files: 24 test files, 8 helpers                                     │   │
│  │                                                                      │   │
│  │  [🔄 Sync Now]                                                       │   │
│  │                                                                      │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─ TEST ENVIRONMENTS ──────────────────────────────────────────────────┐   │
│  │                                                                      │   │
│  │  Allowed: [✓] Staging  [✓] Production (requires approval)            │   │
│  │                                                                      │   │
│  │  (Environments configured in Playwright Settings)                    │   │
│  │                                                                      │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─ FILES (read-only, managed via git) ─────────────────────────────────┐   │
│  │                                                                      │   │
│  │  📁 tests/                                                           │   │
│  │    ├── 📄 home.test.ts                                               │   │
│  │    ├── 📄 checkout.test.ts                                           │   │
│  │    └── ...                                                           │   │
│  │                                                                      │   │
│  │  (Edit files in git repo, then sync)                                 │   │
│  │                                                                      │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Permissions for Test Management

| Action | Super-Admin | Admin | User |
|--------|-------------|-------|------|
| Create project | ✅ | ✅ | ❌ |
| Delete project | ✅ | ✅ | ❌ |
| Upload/edit test files | ✅ | ✅ | ❌ |
| Configure git source | ✅ | ✅ | ❌ |
| Trigger git sync | ✅ | ✅ | ❌ |
| Configure environments | ✅ | ✅ | ❌ |
| View test files | ✅ | ✅ | ✅ |
| Run tests | ✅ | ✅ | ✅ |

### Backup & Versioning

Every file change creates a backup:

```
apps/playwright/projects/ocp-marketplace/
├── tests/
│   └── checkout.test.ts              # Current version
├── .history/
│   └── tests/
│       ├── checkout.test.ts.2024-01-15T10-30-00.backup
│       └── checkout.test.ts.2024-01-14T15-00-00.backup
└── ...
```

For git-sourced projects, the git history IS the version history.

---

## Doki Sources (Unified Git Management)

Instead of configuring Git credentials in 3 separate places (Playwright, Apps, Templates), Doki uses a **unified sources system**. Configure Git providers once, use everywhere.

### The Problem with Separate Sources

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ❌ OLD APPROACH (3 separate Git configs)                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Playwright Settings          Apps Marketplace         Template Sources    │
│  ──────────────────          ────────────────         ─────────────────    │
│                                                                             │
│  Git Providers:               Sources:                 Sources:            │
│  • GitHub token (copy)        • GitHub token (copy)    • GitHub token (copy)│
│  • GitLab token (copy)        • GitLab token (copy)    • GitLab token (copy)│
│                                                                             │
│  ❌ Same tokens configured 3 times                                         │
│  ❌ Inconsistent UX across features                                        │
│  ❌ Credential rotation nightmare                                          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Unified Doki Sources

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ✅ NEW APPROACH (Unified Doki Sources)                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│                        ADMIN > SOURCES                                      │
│                        ══════════════                                       │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ GIT PROVIDERS (shared credentials)                                  │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │  🔑 GitHub - Company      token: ●●●●●●●●     [Edit] [Test]         │   │
│  │  🔑 GitLab - Internal     token: ●●●●●●●●     [Edit] [Test]         │   │
│  │  🔑 Bitbucket - Team      token: ●●●●●●●●     [Edit] [Test]         │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ REPOSITORIES                                    [+ Add Repository]  │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │                                                                     │   │
│  │  📦 company/doki-apps              Type: Apps                       │   │
│  │     Provider: GitHub - Company     Branch: main                     │   │
│  │     Last sync: 2 hours ago         [Sync] [Settings]                │   │
│  │                                                                     │   │
│  │  📋 company/doki-templates         Type: Templates                  │   │
│  │     Provider: GitHub - Company     Branch: v1.2.0 (pinned)          │   │
│  │     Last sync: 1 day ago           [Sync] [Settings]                │   │
│  │                                                                     │   │
│  │  🎭 company/e2e-tests              Type: Playwright Tests           │   │
│  │     Provider: GitLab - Internal    Branch: main                     │   │
│  │     Last sync: 5 mins ago          [Sync] [Settings]                │   │
│  │                                                                     │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                             │
│                        DOKI SOURCES (Central)                               │
│                        ══════════════════════                               │
│                                                                             │
│   ┌─────────────────────┐        ┌─────────────────────────────────────┐   │
│   │ GIT PROVIDERS       │        │ REPOSITORIES                        │   │
│   ├─────────────────────┤        ├─────────────────────────────────────┤   │
│   │ • GitHub (token)    │───────▶│ • Repo A → type: apps               │   │
│   │ • GitLab (token)    │        │ • Repo B → type: templates          │   │
│   │ • Bitbucket (token) │        │ • Repo C → type: playwright-tests   │   │
│   └─────────────────────┘        │ • Repo D → type: mixed              │   │
│                                  └─────────────────────────────────────┘   │
│                                               │                             │
│                    ┌──────────────────────────┼──────────────────────┐     │
│                    │                          │                      │     │
│                    ▼                          ▼                      ▼     │
│                                                                             │
│           Apps Marketplace            Template Loader         Playwright   │
│           ────────────────            ───────────────         ──────────   │
│                                                                             │
│           "Install app from           "Load templates         "Sync tests  │
│            Repo A"                     from Repo B"            from Repo C"│
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Configuration Schema

```yaml
# data/config/sources.yaml

gitProviders:
  - id: github-company
    name: Company GitHub
    type: github
    token: "{{secret:github-token}}"  # Reference to secrets.yaml
    
  - id: gitlab-internal
    name: Internal GitLab
    type: gitlab
    url: https://gitlab.company.com
    token: "{{secret:gitlab-token}}"

repositories:
  - id: official-apps
    name: Official Doki Apps
    provider: github-company
    repo: doki-project/apps
    branch: main
    type: apps
    autoSync: false
    
  - id: company-templates
    name: Company Templates
    provider: github-company
    repo: company/doki-templates
    branch: main
    ref: v1.2.0              # Pin to version
    type: templates
    autoSync: false
    
  - id: e2e-tests
    name: E2E Test Suite
    provider: gitlab-internal
    repo: qa/e2e-tests
    branch: main
    path: /playwright        # Subfolder in repo
    type: playwright-tests
    autoSync: true           # Sync before each test run
```

### Repository Types

| Type | What it provides | Used by | Path in cache |
|------|------------------|---------|---------------|
| `apps` | Doki apps (manifest.yaml) | Apps Marketplace | `data/sources/apps/{repo}/` |
| `templates` | Command templates (YAML) | Template loader | `data/sources/templates/{repo}/` |
| `playwright-tests` | Test projects | Playwright app | `data/sources/tests/{repo}/` |
| `mixed` | Multiple types in subfolders | Auto-detected | `data/sources/mixed/{repo}/` |

### Benefits

1. **Single credential management** - Add GitHub token once, use everywhere
2. **Consistent sync UI** - Same pattern for all external content
3. **Audit trail** - All Git operations logged in one place
4. **Token rotation** - Update token once, affects all repos using that provider
5. **Future-proof** - Easy to add new source types (configs, scripts, etc.)

### How Features Use Sources

**Apps Marketplace:**
```
Admin: "Install weather app"
  → Marketplace checks repos with type: apps
  → Finds company/doki-apps
  → Uses github-company provider credentials
  → Clones/pulls repo
  → Discovers available apps
```

**Template Loader:**
```
On startup or manual sync:
  → Loader checks repos with type: templates
  → Uses provider credentials
  → Pulls templates to cache
  → Merges with local templates/
```

**Playwright Projects:**
```
Admin: "Create project from Git"
  → Shows repos with type: playwright-tests
  → Or allows any repo (type: mixed)
  → Uses provider credentials
  → Syncs on demand or before test runs
```

### Sources UI

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ADMIN > SOURCES                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─ GIT PROVIDERS ─────────────────────────────────────────────────────┐   │
│  │                                                    [+ Add Provider] │   │
│  │                                                                     │   │
│  │  🔑 GitHub - Company                                                │   │
│  │     Type: github                        Status: ✓ Connected         │   │
│  │     Used by: 3 repositories             [Test] [Edit] [Delete]      │   │
│  │                                                                     │   │
│  │  🔑 GitLab - Internal                                               │   │
│  │     Type: gitlab (gitlab.company.com)   Status: ✓ Connected         │   │
│  │     Used by: 1 repository               [Test] [Edit] [Delete]      │   │
│  │                                                                     │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─ REPOSITORIES ──────────────────────────────────────────────────────┐   │
│  │                                                  [+ Add Repository] │   │
│  │                                                                     │   │
│  │  📦 Official Doki Apps                          Type: apps          │   │
│  │     doki-project/apps @ main                                        │   │
│  │     Provider: GitHub - Company                                      │   │
│  │     Last sync: 2 hours ago • 5 apps available                       │   │
│  │     [Sync Now] [Settings] [Remove]                                  │   │
│  │                                                                     │   │
│  │  📋 Company Templates                           Type: templates     │   │
│  │     company/doki-templates @ v1.2.0 (pinned)                        │   │
│  │     Provider: GitHub - Company                                      │   │
│  │     Last sync: 1 day ago • 8 templates                              │   │
│  │     [Sync Now] [Settings] [Remove]                                  │   │
│  │                                                                     │   │
│  │  🎭 E2E Test Suite                              Type: playwright    │   │
│  │     qa/e2e-tests @ main                                             │   │
│  │     Provider: GitLab - Internal                                     │   │
│  │     Last sync: 5 mins ago • Auto-sync enabled                       │   │
│  │     [Sync Now] [Settings] [Remove]                                  │   │
│  │                                                                     │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Migration from Current Implementation

| Current | Migrates to |
|---------|-------------|
| Apps Marketplace > Sources | Doki Sources > Repositories (type: apps) |
| Playwright Settings > Git Providers | Doki Sources > Git Providers |
| Playwright Projects > Git Source | Reference Doki Sources repository |
| Template Sources (future) | Doki Sources > Repositories (type: templates) |

---

## Architecture Categories Summary

| # | Category | Description |
|---|----------|-------------|
| 1 | **Core Architecture** | 4-way separation: Templates, Infrastructure, Commands, Orchestrator |
| 2 | **Templates System** | Stateless, shareable tool definitions (YAML) |
| 3 | **Infrastructure Config** | Targets (servers/endpoints), Environments (dev/staging/prod) |
| 4 | **Secrets Management** | Credential storage, encryption, Vault integration |
| 5 | **Commands Config** | User-specific command definitions tying templates to targets |
| 6 | **Orchestrator** | Execution engine: resolves refs, injects secrets, runs commands |
| 7 | **Auth & Roles** | User management, role hierarchy (super-admin/admin/user) |
| 8 | **Admin UI** | Config editing interface for infrastructure, commands, apps |
| 9 | **Backup & Versioning** | Timestamped backups for all config changes |
| 10 | **Audit Logging** | Track all actions: edits, executions, secret access |
| 11 | **Validation** | YAML syntax and basic schema validation on save |
| 12 | **Apps System** | Framework for stateful tools (own UI, storage, lifecycle, trust model) |
| 13 | **Playwright App** | Test runner, report storage, report viewer, wrapper config |
| 14 | **Test Management** | Git sync + local upload for test files |
| 15 | **Doki Sources** | Unified Git providers & repositories for apps, templates, tests |
| 16 | **Security Hardening** | Encryption at rest, runtime secret logging, audit export |
| 17 | **Advanced Auth** | SSO/OIDC, API tokens, 2FA, session management |
| 18 | **Kubernetes** | K8s targets, pod exec, namespace support, log streaming |

---

## Implementation Status

| Category | Component | Status | Batch |
|----------|-----------|--------|-------|
| **1. Core Architecture** | | | |
| | Directory structure | 🟢 Done | - |
| | Separation of concerns | 🟢 Done | - |
| **2. Templates** | | | |
| | Template schema | 🟢 Done | - |
| | Docker template | 🟢 Done | - |
| | Elasticsearch template | 🟢 Done | - |
| | Log viewer template | 🟢 Done | - |
| | HTTP request template | 🟢 Done | - |
| | Shell exec template | 🟢 Done | - |
| **3. Infrastructure** | | | |
| | Targets schema | 🟢 Done | - |
| | Environments schema | 🟢 Done | - |
| | Targets UI | 🟢 Done | Batch 2 |
| | Environments UI | 🟢 Done | Batch 2 |
| **4. Secrets** | | | |
| | Secrets schema | 🟢 Done | - |
| | Encryption at rest | 🟢 Done | Batch 11 |
| | Secrets UI (masked, re-auth) | 🟢 Done | Batch 2 |
| **5. Commands** | | | |
| | Commands schema | 🟢 Done | - |
| | Commands UI | 🟢 Done | Batch 3 |
| | Target selection | 🟢 Done | Batch 3 |
| | Secrets mapping UI | 🟢 Done | Batch 4 |
| | Environment mapping | 🟢 Done | Batch 5 |
| **6. Orchestrator** | | | |
| | Template loader | 🟢 Done | Batch 3 |
| | Target resolver | 🟢 Done | Batch 4 |
| | Secret injection | 🟢 Done | Batch 4 |
| | Container runner | 🟢 Done | Batch 4 |
| | Target: `local` | 🟢 Done | Batch 4 |
| | Target: `docker` | 🟢 Done | Batch 4 |
| | Target: `ssh` | 🟢 Done | Batch 5 |
| | Target: `docker-exec` | 🟢 Done | Batch 5 |
| | Target: `http` | 🟢 Done | Batch 4 |
| | Target: `kubernetes` | 🟡 Partial | Batch 14 |
| | Connection testing | 🟢 Done | Batch 5 |
| | Template targetType | 🟢 Done | Batch 5 |
| | Type-based env resolution | 🟢 Done | Batch 5 |
| | HTTP baseUrl injection | 🟢 Done | Batch 5 |
| **7. Auth & Roles** | | | |
| | User model | 🟢 Done | Batch 1 |
| | Role hierarchy | 🟢 Done | Batch 1 |
| | Password hashing | 🟢 Done | Batch 1 |
| | Session management | 🟢 Done | Batch 1 |
| | SSO/OIDC | 🔴 Not started | Batch 12 |
| **8. Admin UI** | | | |
| | Main layout/navigation | 🟢 Done | Batch 1 |
| | Targets editor | 🟢 Done | Batch 2 |
| | Environments editor | 🟢 Done | Batch 2 |
| | Secrets editor | 🟢 Done | Batch 2 |
| | Commands editor | 🟢 Done | Batch 3 |
| | Dynamic app discovery | 🟢 Done | Batch 10 |
| **9. Backup & Versioning** | | | |
| | Auto-backup on edit | 🟢 Done | Batch 2 |
| | History browser UI | 🟢 Done | Batch 9 |
| | Restore from backup | 🟢 Done | Batch 9 |
| | Diff viewer | 🟢 Done | Batch 9 |
| **10. Audit Logging** | | | |
| | Log edits | 🟢 Done | Batch 1 |
| | Log executions | 🟢 Done | Batch 4 |
| | Log secret access (edit) | 🟢 Done | Batch 2 |
| | Log secret access (runtime) | 🟢 Done | Batch 11 |
| | Audit viewer UI | 🟢 Done | Batch 9 |
| | Audit filters (action, user, date) | 🟢 Done | Batch 9 |
| **11. Validation** | | | |
| | YAML syntax check | 🟢 Done | Batch 2 |
| | Schema validation | 🟢 Done | Batch 2 |
| | Duplicate ID check | 🟢 Done | Batch 2 |
| **12. Apps System** | | | |
| | App framework | 🟢 Done | Batch 6 |
| | App config schema | 🟢 Done | - |
| | App permissions | 🟢 Done | Batch 6 |
| | App trust model (Core/Verified/Trusted/Sandboxed) | 🟢 Done | Batch 10 |
| | AppContext (capability-based security) | 🟢 Done | Batch 10 |
| | App sources (Git repositories) | 🟢 Done | Batch 10 |
| | App Marketplace UI | 🟢 Done | Batch 10 |
| | App install/update/uninstall | 🟢 Done | Batch 10 |
| | Sample sandboxed app (Weather) | 🟢 Done | Batch 10 |
| **13. Playwright App** | | | |
| | Test runner | 🟢 Done | Batch 8 |
| | Report storage | 🟢 Done | Batch 8 |
| | Report viewer | 🟢 Done | Batch 8 |
| | Settings UI | 🟢 Done | Batch 6 |
| | Git providers config | 🟢 Done | Batch 6 |
| | Test environments config | 🟢 Done | Batch 6 |
| | Runtime wrapper config | 🟢 Done | Batch 8+ |
| | Custom wrapper params per project | 🟢 Done | Batch 8+ |
| | Reports page (always show runs) | 🟢 Done | Batch 8+ |
| | Log viewer (live + archived) | 🟢 Done | Batch 8+ |
| | Project deletion with cleanup | 🟢 Done | Batch 8+ |
| | AppContext migration | 🟢 Done | Batch 10 |
| **14. Test Management** | | | |
| | Projects list UI | 🟢 Done | Batch 7 |
| | Git sync | 🟢 Done | Batch 7 |
| | File upload UI | 🟢 Done | Batch 7 |
| | File editor UI | 🟢 Done | Batch 7 |
| | ZIP upload | 🟢 Done | Batch 7 |
| | Test runner UI | 🟢 Done | Batch 8 |
| | Environment selection | 🟢 Done | Batch 8 |
| | Test file selection | 🟢 Done | Batch 8 |
| | Progress streaming | 🟢 Done | Batch 8 |
| | Video playback | 🟢 Done | Batch 8 |
| | Report cleanup | 🟢 Done | Batch 8 |
| **15. Doki Sources** | | | |
| | Local templates | 🟢 Done | - |
| | Git providers (shared credentials) | 🟢 Done | Batch 13 |
| | App repositories | 🟢 Done | Batch 13 |
| | Template repositories | 🟡 Partial | Batch 13 |
| | Playwright test repositories | 🟡 Partial | Batch 13 |
| | Unified Sources UI | 🟢 Done | Batch 13 |
| | Repository sync mechanism | 🟢 Done | Batch 13 |
| | Version pinning | 🟢 Done | Batch 13 |
| **16. Security Hardening** | | | |
| | Encryption at rest (AES-256-GCM) | 🟢 Done | Batch 11 |
| | Encryption key management (file-only) | 🟢 Done | Batch 11 |
| | Runtime secret logging | 🟢 Done | Batch 11 |
| | Secret rotation support | 🟢 Done | Batch 11 |
| | Audit log export (CSV/JSON) | 🟢 Done | Batch 11 |
| **17. Advanced Auth** | | | |
| | SSO/OIDC integration | 🔴 Not started | Batch 12 |
| | SAML support | 🔴 Not started | Batch 12 |
| | API tokens | 🔴 Not started | Batch 12 |
| | Token management UI | 🔴 Not started | Batch 12 |
| | 2FA support | 🔴 Not started | Batch 12 |
| | Session management | 🔴 Not started | Batch 12 |
| **18. Kubernetes** | | | |
| | K8s target type | 🟡 Partial | Batch 14 |
| | K8s authentication | 🟡 Partial | Batch 14 |
| | K8s pod exec | 🔴 Not started | Batch 14 |
| | K8s namespace support | 🟡 Partial | Batch 14 |
| | K8s log streaming | 🔴 Not started | Batch 14 |
| | Health check dashboard | 🔴 Not started | Batch 14 |

### Status Legend

| Symbol | Meaning |
|--------|---------|
| 🟢 Done | Implemented and working |
| 🟡 Partial | Partially implemented or exists in old form |
| 🔴 Not started | Designed but not implemented |

### Current State Summary

- **Designed & Documented**: 100% (this architecture doc)
- **Implemented (Batches 1-11)**: 100% ✅
- **Partially Implemented**: Batch 13 (Doki Sources), Batch 14 (Kubernetes foundation)
- **Not Started**: Batch 12 (Advanced Auth), Batch 15 (Onboarding)

**Completed Batches (1-11):**
- Batch 1: Auth & UI Foundation
- Batch 2: Infrastructure Config UI
- Batch 3: Commands & Templates
- Batch 4: Orchestrator Core
- Batch 5: Advanced Targets
- Batch 6: Playwright Settings
- Batch 7: Playwright Projects
- Batch 8: Playwright Execution (+ enhancements: wrapper config, always-show-runs, logs)
- Batch 9: Audit & History (filters, history browser, restore, diff viewer)
- Batch 10: App Framework & Trust Model (Git sources, trust levels, AppContext, Marketplace UI)
- Batch 11: Security Hardening (secrets encryption, stealth login, audit export)

**Partially Implemented Batches:**
- Batch 13: Doki Sources (core platform complete; template/playwright repository integration still in progress)
- Batch 14: Kubernetes (target type, kubeconfig checks, namespace fields in place; pod exec/log streaming pending)

**Future Batches:**
- Batch 12: Advanced Auth (SSO/OIDC, API tokens, 2FA)
- Batch 14: Kubernetes completion (pod exec, log streaming, health dashboard)
- Batch 15: Onboarding & Bootstrap (first admin setup, security choices)

---

## Implementation Batches

Each batch delivers testable functionality. After each batch, you can log in and verify the work.

### Batch 1: Auth & UI Foundation
**Goal**: New auth system, basic navigation, role-based views

| Task | Description |
|------|-------------|
| User database | Store users in DB (not hardcoded PHP) |
| Password hashing | bcrypt for passwords |
| Session management | Secure sessions with expiry |
| Role system | super-admin, admin, user roles |
| Login page | New login UI |
| Main layout | Navigation shell, sidebar, header |
| Role-based nav | Show/hide menu items based on role |

**Test**: 
- Login as super-admin → see all menu items
- Login as admin → see config menus, no user management
- Login as user → see only commands/reports

---

### Batch 2: Infrastructure Config UI
**Goal**: Admin can manage targets, environments, secrets via UI

| Task | Description |
|------|-------------|
| Targets list view | Show all targets as cards/table |
| Targets editor | Form + raw YAML editor |
| Environments list view | Show all environments |
| Environments editor | Form + raw YAML editor |
| Secrets list view | Show secret keys (masked values) |
| Secrets editor | Secure editor with re-auth |
| Auto-backup | Create .history/ backup on every save |
| Basic validation | YAML syntax, required fields, no duplicate IDs |

**Test**:
- Add a new target via UI → see it in list
- Edit a target → verify backup created
- View secrets → values masked until "Show" clicked
- Save invalid YAML → see error message

---

### Batch 3: Commands & Templates
**Goal**: View templates, create/edit commands that reference them

| Task | Description |
|------|-------------|
| Template loader | Parse templates from ./templates/ folder |
| Templates list view | Show available templates (read-only) |
| Template detail view | Show inputs, script, requirements |
| Commands list view | Show all commands |
| Commands editor | Create command referencing template + target |
| Command preview | Show what the command will do |

**Test**:
- View docker-management template → see its inputs
- Create command "Restart Airflow" using docker-management template
- Edit command → change defaults
- Delete command → removed from list

---

### Batch 4: Orchestrator Core
**Goal**: Execute commands locally in containers

| Task | Description |
|------|-------------|
| Orchestrator service | Core execution engine |
| Template resolver | Load template by ID |
| Target resolver | Load target by ID |
| Secret injection | Fetch secrets, inject as env vars |
| Container runner | Docker run with template's image |
| Output streaming | Real-time output to UI |
| Execution history | Store recent executions |

**Test**:
- Run shell-exec command with `echo "hello"` → see output
- Run elasticsearch template against local target → see health check
- View execution history → see recent runs

---

### Batch 5: Advanced Targets
**Goal**: Execute on remote targets (SSH, Docker exec)

| Task | Description |
|------|-------------|
| SSH target handler | Connect via SSH, run command |
| Docker-exec handler | Exec into running container |
| Environment selector | UI to pick dev/staging/prod |
| Target resolution | Resolve target from environment mapping |
| Connection testing | "Test Connection" button for targets |

**Test**:
- Create SSH target → test connection
- Run command with environment=staging → uses staging target
- Run docker-management command → exec into container

---

### Batch 6: Playwright App - Settings
**Goal**: Configure Playwright app (git providers, test environments)

| Task | Description |
|------|-------------|
| Apps framework | Load apps from ./apps/ folder |
| App navigation | Apps section in sidebar |
| Playwright settings UI | Storage, defaults, notifications |
| Git providers config | Add GitHub/GitLab with shared tokens |
| Test environments config | staging/production with URLs, creds |
| Settings validation | Validate before save |

**Test**:
- Navigate to Playwright → Settings
- Add GitHub provider with token
- Add staging/production test environments
- Save → settings persisted

---

### Batch 7: Playwright App - Projects
**Goal**: Manage test projects (create, upload, git sync)

| Task | Description |
|------|-------------|
| Projects list view | Show all projects |
| Create project wizard | Name, source type (local/git) |
| Git sync | Clone/pull from configured repo |
| File browser | View project files |
| File upload | Upload individual files or ZIP |
| File editor | Edit files in browser |
| Project settings | Allowed environments, defaults |

**Test**:
- Create project with local source → upload ZIP
- Create project with git source → sync from repo
- Browse files → edit a test file
- Delete project → removed

---

### Batch 8: Playwright App - Execution
**Goal**: Run tests, view reports

| Task | Description | Status |
|------|-------------|--------|
| Test runner | Execute Playwright in container | 🟢 Done |
| Environment selection | Pick staging/production | 🟢 Done |
| Test file selection | Pick which tests to run | 🟢 Done |
| Progress streaming | Real-time test output | 🟢 Done |
| Report storage | Save HTML reports | 🟢 Done |
| Report viewer | List and view reports | 🟢 Done |
| Video storage | Save test videos | 🟢 Done |
| Retention cleanup | Auto-delete old reports/videos | 🟢 Done |
| Container-based execution | Uses official Playwright Docker image | 🟢 Done |

**Implementation Notes**:
- Tests run in `mcr.microsoft.com/playwright:v1.49.1-noble` Docker container
- Avoids architecture issues (Rosetta/ARM vs x86)
- Browsers pre-installed in container image
- Project files mounted read-only, output dirs mounted writable
- Environment variables (`PLAYWRIGHT_HTML_REPORT`, `BASE_URL`) passed to container

**Test**:
- Select project → select tests → run on staging
- Watch progress in real-time
- View HTML report after completion
- Watch test video
- Old reports auto-cleaned after retention period

---

### Batch 9: Audit & History ✅
**Goal**: Full audit trail, backup browsing

| Task | Description | Status |
|------|-------------|--------|
| Audit logging | Log all actions (edit, execute, view_secret) | 🟢 Done |
| Audit storage | Database table for audit entries | 🟢 Done |
| Audit viewer UI | Browse audit logs with filters | 🟢 Done |
| Audit filters | Filter by action, user, resource, date range | 🟢 Done |
| History browser | Browse .history/ backups per config type | 🟢 Done |
| Restore from backup | Restore previous version (super-admin) | 🟢 Done |
| Diff viewer | Side-by-side comparison of versions | 🟢 Done |

**Test**:
- Edit a target → see entry in audit log ✅
- Run a command → see execution logged ✅
- View secrets → see access logged ✅
- Browse history → restore old version ✅

---

### Batch 10: App Framework & Trust Model ✅
**Goal**: Secure app ecosystem with Git sources and capability-based trust

| Task | Description | Status |
|------|-------------|--------|
| App trust model | Core/Verified/Trusted/Sandboxed levels | 🟢 Done |
| AppCapabilities class | Define capabilities per trust level | 🟢 Done |
| AppContext class | Unified interface with capability enforcement | 🟢 Done |
| App sources (Git) | Install apps from Git repositories | 🟢 Done |
| App Marketplace UI | Browse, install, manage apps | 🟢 Done |
| Trust level management | Set/change trust with reason logging | 🟢 Done |
| Sample sandboxed app | Weather app demonstrating framework | 🟢 Done |
| Playwright migration | Migrate to AppContext architecture | 🟢 Done |
| Dynamic sidebar | Auto-discover enabled apps for nav | 🟢 Done |

**Test**:
- Add Git source → sync → see available apps ✅
- Install app → appears in sidebar ✅
- Change trust level → requires reason ✅
- Sandboxed app → limited capabilities ✅

**Note**: App sources from this batch are now migrated to unified **Doki Sources** (Batch 13). Template and Playwright repository integration is still in progress.

---

### Batch 11: Security Hardening
**Goal**: Enhanced security for secrets, audit trail, and stealth access

| Task | Description | Status |
|------|-------------|--------|
| Encryption at rest | Encrypt secrets.yaml on disk using AES-256-GCM | 🟢 Done |
| Encryption key management | File-only key storage (no env key), auto-create if missing | 🟢 Done |
| Runtime secret logging | Log when secrets are injected during execution (names only) | 🟢 Done |
| Secret rotation support | Track secret versions, expiry, rotation interval | 🟢 Done |
| Audit log export | Export audit logs to CSV/JSON (no values) | 🟢 Done |
| **Hidden login (stealth mode)** | Knock-sequence authentication to reveal login page (default ON) | 🟢 Done |

**Test**:
- View secrets.yaml on disk → encrypted content
- Run command with secret → audit log shows secret was used (not value)
- Export audit logs → JSON/CSV export works
- Visit site → blank page, type secret → login appears

**Implementation Notes (Batch 11 Decisions)**:
- **Centralized secrets encryption**: All secrets (targets, sources, apps) are stored only in the encrypted secrets store.
- **Encryption at rest**: AES-256-GCM envelope stored in `app/data/config/secrets.yaml`.
- **Key storage**: File-only key at `app/data/keys/secrets.key` (base64 32 bytes). Auto-created if missing; permissions 0600.
- **Secret editing**: Admin UI decrypts server-side; values never logged. Keys starting with `__` are reserved.
- **Git provider tokens**: Moved from DB plaintext to secrets store (`token_secret_id`).
- **Runtime secret logging**: Audit log records **secret IDs only** (no values) on execution.
- **Secret rotation metadata**: Version, last-rotated, optional expiry and rotation interval tracked in DB.
- **Audit export**: CSV/JSON export via API; values are never included.
- **Stealth mode**: **Default ON**. Unlock via `stealth.php` + `stealth-check.php`, secret stored in `app/data/keys/stealth.secret`.
- **Re-keying**: Deferred to a future batch.

---

#### Hidden Login Page (Stealth Mode)

**Concept**: The login page is completely hidden. Users see a blank page and must type a secret key sequence to reveal the actual login form. This provides security through obscurity against automated scanners and casual attackers.

**Flow**:
```
User visits site
       │
       ▼
┌─────────────────┐
│   Blank Page    │  ◄── No visual elements, completely empty
│   (stealth.php) │
└────────┬────────┘
         │
         │ User types keystrokes
         ▼
┌─────────────────┐
│  JS captures    │  ◄── Every keypress sent to server via fetch()
│  keystrokes     │      No visual feedback whatsoever
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  PHP Session    │  ◄── Stores: keystroke, timestamp
│  Accumulator    │      Format: [{char: 'a', time: 1706000000}, ...]
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Cleanup old    │  ◄── Remove keystrokes older than 30 seconds
│  keystrokes     │      Sliding window validation
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────┐
│  Check sequence matches secret  │
└────────┬───────────────┬────────┘
         │               │
    Match ✓         No Match
         │               │
         ▼               ▼
┌─────────────────┐  ┌─────────────────┐
│ Set unlock flag │  │ Continue blank  │
│ + 2min expiry   │  │ page display    │
└────────┬────────┘  └─────────────────┘
         │
         ▼
┌─────────────────┐
│  Redirect to    │  ◄── Normal login.php
│  login page     │      Has 2-minute window
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────┐
│  Login page checks unlock flag  │
│  + timestamp < 2 minutes        │
└────────┬───────────────┬────────┘
         │               │
      Valid          Expired
         │               │
         ▼               ▼
┌─────────────────┐  ┌─────────────────┐
│  Show login     │  │  Redirect back  │
│  form           │  │  to blank page  │
└─────────────────┘  └─────────────────┘
```

**Implementation Details**:

| Component | Description |
|-----------|-------------|
| `stealth.php` | Entry point - serves blank HTML page with minimal JS |
| `stealth-check.php` | API endpoint - receives keystrokes, validates sequence |
| Secret storage | Config file or env var: `STEALTH_SECRET=opensesame` |
| Session keys | `stealth_keystrokes` (array), `stealth_unlocked` (bool), `stealth_unlock_time` (timestamp) |

**Session Data Structure**:
```php
$_SESSION['stealth'] = [
    'keystrokes' => [
        ['char' => 'o', 'time' => 1706000000],
        ['char' => 'p', 'time' => 1706000001],
        ['char' => 'e', 'time' => 1706000002],
        // ...
    ],
    'unlocked' => false,
    'unlock_time' => null,
    'failed_attempts' => 0,
    'lockout_until' => null
];
```

**Keystroke Validation Logic**:
```php
// 1. Add new keystroke with timestamp
$keystroke = ['char' => $char, 'time' => time()];
$_SESSION['stealth']['keystrokes'][] = $keystroke;

// 2. Remove keystrokes older than 30 seconds
$cutoff = time() - 30;
$_SESSION['stealth']['keystrokes'] = array_filter(
    $_SESSION['stealth']['keystrokes'],
    fn($k) => $k['time'] >= $cutoff
);

// 3. Build current sequence string
$sequence = implode('', array_column($_SESSION['stealth']['keystrokes'], 'char'));

// 4. Check if sequence ends with secret
$secret = getenv('STEALTH_SECRET') ?: 'defaultsecret';
if (str_ends_with($sequence, $secret)) {
    $_SESSION['stealth']['unlocked'] = true;
    $_SESSION['stealth']['unlock_time'] = time();
    $_SESSION['stealth']['keystrokes'] = []; // Clear
    return ['unlocked' => true];
}
```

**Brute-Force Protection**:

| Protection | Implementation |
|------------|----------------|
| Rate limiting | Max 10 keystrokes per second (ignore excess) |
| Attempt tracking | Count sequences that reach secret length but fail |
| Lockout | After 5 failed attempts → 5 minute lockout |
| Progressive lockout | Each subsequent lockout doubles (5min, 10min, 20min...) |
| IP tracking | Store failed attempts by IP in addition to session |
| Max lockout | Cap at 1 hour maximum lockout |

**Brute-Force Logic**:
```php
// Check lockout
if ($_SESSION['stealth']['lockout_until'] && time() < $_SESSION['stealth']['lockout_until']) {
    return ['error' => 'locked', 'retry_after' => $_SESSION['stealth']['lockout_until'] - time()];
}

// Track failed attempts (when sequence length >= secret length but doesn't match)
if (strlen($sequence) >= strlen($secret) && !str_ends_with($sequence, $secret)) {
    $_SESSION['stealth']['failed_attempts']++;
    
    if ($_SESSION['stealth']['failed_attempts'] >= 5) {
        $lockout_minutes = min(60, 5 * pow(2, floor($_SESSION['stealth']['failed_attempts'] / 5) - 1));
        $_SESSION['stealth']['lockout_until'] = time() + ($lockout_minutes * 60);
    }
}
```

**Frontend (stealth.php)**:
```html
<!DOCTYPE html>
<html><head><title></title></head>
<body>
<script>
document.addEventListener('keypress', async (e) => {
    // Ignore modifier keys, function keys
    if (e.key.length !== 1) return;
    
    try {
        const r = await fetch('/stealth-check.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({k: e.key})
        });
        const data = await r.json();
        if (data.unlocked) {
            window.location.href = '/login.php';
        }
        // No feedback on failure - complete silence
    } catch {}
});
</script>
</body>
</html>
```

**Login Page Modification** (login.php):
```php
// At top of login.php
session_start();

// Check if stealth mode is enabled
if (getenv('STEALTH_MODE') === 'true') {
    $unlocked = $_SESSION['stealth']['unlocked'] ?? false;
    $unlock_time = $_SESSION['stealth']['unlock_time'] ?? 0;
    $window = 120; // 2 minutes
    
    if (!$unlocked || (time() - $unlock_time) > $window) {
        // Clear unlock status and redirect to blank page
        $_SESSION['stealth']['unlocked'] = false;
        header('Location: /');
        exit;
    }
}

// Continue with normal login page...
```

**Configuration** (env or config):
```bash
# Enable stealth mode
STEALTH_MODE=true

# Secret knock sequence
STEALTH_SECRET=mySecretKnock123

# Unlock window (seconds) - how long login page stays visible
STEALTH_WINDOW=120

# Keystroke expiry (seconds) - how long keystrokes remain valid
STEALTH_KEYSTROKE_TTL=30

# Brute-force settings
STEALTH_MAX_ATTEMPTS=5
STEALTH_LOCKOUT_BASE=5  # minutes
STEALTH_LOCKOUT_MAX=60  # minutes
```

**Security Considerations**:
- Secret should be long (12+ chars) and contain mixed characters
- All requests use same response time (prevent timing attacks)
- No indication of progress or correctness
- HTTPS required (keystrokes sent in clear otherwise)
- Consider IP-based rate limiting at reverse proxy level
- Log all unlock events to audit trail

---

### Batch 12: Advanced Authentication
**Goal**: Enterprise authentication and API access

| Task | Description | Status |
|------|-------------|--------|
| SSO/OIDC integration | Login via external identity providers | 🔴 Not started |
| SAML support | Enterprise SSO via SAML 2.0 | 🔴 Not started |
| API tokens | Generate tokens for automation/CI | 🔴 Not started |
| Token management UI | Create, revoke, list API tokens | 🔴 Not started |
| 2FA support | Optional two-factor authentication | 🔴 Not started |
| Session management | View/revoke active sessions | 🔴 Not started |

**Test**:
- Configure OIDC provider → login via SSO
- Generate API token → use in curl command
- Enable 2FA → login requires code

---

### Batch 13: Doki Sources (Unified Git Management)
**Goal**: Centralized Git providers and repositories for apps, templates, and tests

| Task | Description | Status |
|------|-------------|--------|
| Git providers management | Add/edit/test Git providers (GitHub, GitLab, Bitbucket) | ✅ Complete |
| Provider credentials | Secure token storage with secret references | ✅ Complete |
| Unified Sources UI | Admin > Sources page with providers + repositories | ✅ Complete |
| Repository types | Support apps, templates, playwright-tests, mixed | ✅ Complete |
| Repository sync | Clone/pull with progress, error handling | ✅ Complete |
| Version pinning | Pin to specific tag/commit/branch | ✅ Complete |
| Auto-sync option | Optional sync before operations | 🟡 Partial |
| Migrate App sources | Move Batch 10 app sources to unified system | ✅ Complete |
| Migrate Playwright Git | Move Playwright Git providers to unified system | ✅ Complete |
| Template loading | Load templates from type: templates repositories | 🟡 Partial |
| Source audit logging | Log all Git operations | ✅ Complete |

**Test**:
- Add GitHub provider → test connection works
- Add repository (type: apps) → sync → apps appear in Marketplace
- Add repository (type: templates) → sync → templates discovered in Sources UI
- Pin to v1.0.0 → stays on that version
- Rotate token → all repos using provider still work
- Playwright project can reuse provider credentials from Doki Sources

---

### Batch 14: Kubernetes & Advanced Infrastructure
**Goal**: Kubernetes support and infrastructure enhancements

| Task | Description | Status |
|------|-------------|--------|
| K8s target type | New target type for Kubernetes clusters | 🟡 Partial |
| K8s authentication | kubeconfig, service account, cloud auth | 🟡 Partial |
| K8s pod exec | Execute commands in pods | 🔴 Not started |
| K8s namespace support | Target specific namespaces | 🟡 Partial |
| K8s log streaming | Stream pod logs | 🔴 Not started |
| Health check dashboard | Target connectivity status overview | 🔴 Not started |

**Test**:
- Add K8s target with `kubeconfigRef` → test connection
- Run command in pod → see output (pending)
- View pod logs → real-time streaming (pending)

---

### Batch 15: Onboarding & Bootstrap
**Goal**: First-run setup that hardens security and reduces manual steps

| Task | Description | Status |
|------|-------------|--------|
| Install-state routing | Track `fresh` / `onboarding` / `complete` and route before normal stealth/login behavior | 🔴 Not started |
| Default seed removal | Stop creating bundled default users and remove login hints for them | 🔴 Not started |
| First admin password | Force new password on first login | 🔴 Not started |
| Break-glass admin recovery | Create a DB-backed recovery code for the first `super-admin` and rotate it after each successful reset | 🔴 Not started |
| Recovery session revocation | Revoke all active sessions for the recovered account after a successful password reset | 🔴 Not started |
| Secrets key setup | Generate `app/data/keys/secrets.key` or confirm existing | 🔴 Not started |
| Stealth mode choice | Enable/disable stealth mode and set secret knock | 🔴 Not started |
| Upgrade-safe bootstrap migration | Existing installs with real users should be marked complete and skip first-run onboarding | 🔴 Not started |
| Initial config | Basic defaults (paths, retention, UI preferences) | 🔴 Not started |
| One-time wizard | Guided setup, then lock configuration | 🔴 Not started |

**Test**:
- Fresh install → onboarding wizard appears
- Fresh install never exposes seeded default credentials
- Existing upgraded install with real users skips onboarding safely
- Admin sets password and security options
- `setup.sh` shows the stealth secret with a warning to save it, and onboarding explains stealth before the admin chooses whether to keep it enabled
- Recovery code is created during onboarding, can be retrieved from the database by an operator, and is rotated after a successful reset
- Successful recovery revokes existing sessions for the recovered account
- Secrets key file created and permissions set
- Stealth mode choice applied immediately

---

## Batch Summary

| Batch | Name | Key Deliverable | Status |
|-------|------|-----------------|--------|
| 1 | Auth & UI Foundation | Login, roles, navigation | ✅ Complete |
| 2 | Infrastructure Config | Targets, environments, secrets UI | ✅ Complete |
| 3 | Commands & Templates | View templates, create commands | ✅ Complete |
| 4 | Orchestrator Core | Run commands locally | ✅ Complete |
| 5 | Advanced Targets | SSH, Docker exec | ✅ Complete |
| 6 | Playwright Settings | App framework, Playwright config | ✅ Complete |
| 7 | Playwright Projects | Upload, git sync, file editor | ✅ Complete |
| 8 | Playwright Execution | Run tests, view reports, wrapper config | ✅ Complete |
| 9 | Audit & History | Audit logs, history browser, restore | ✅ Complete |
| 10 | App Framework | Trust model, Git sources, Marketplace | ✅ Complete |
| 11 | Security Hardening | Secrets encryption, stealth login, runtime logging | ✅ Complete |
| 12 | Advanced Auth | SSO/OIDC, API tokens, 2FA | 🔴 Not started |
| 13 | Doki Sources | Unified Git providers, apps/templates/tests repos | 🟡 Partial |
| 14 | Kubernetes | K8s targets, pod exec, logs | 🟡 Partial |
| 15 | Onboarding & Bootstrap | First admin setup, security choices | 🔴 Not started |

```
COMPLETED (Batches 1-10):

Batch 1 ─────► Batch 2 ─────► Batch 3 ─────► Batch 4 ─────► Batch 5
   │              │                             │              │
   │              └──────► Batch 6 ─────► Batch 7 ─────► Batch 8
   │              │
   │              └──────► Batch 9
   │
   └──────────────────────────────────────────────────────► Batch 10

CURRENT + NEXT:

Batch 10 ─────► Batch 11 (Security)
    │
    ├─────► Batch 12 (Auth)
    │
    ├─────► Batch 13 (Doki Sources, partial) ◄── Consolidates:
    │           │                        • Batch 10 App sources
    │           │                        • Playwright Git providers
    │           │                        • Template sources (new)
    │           │
    │           └─────► Apps use unified sources; templates/tests integration in progress
    │
    └─────► Batch 14 (Kubernetes, partial)
```

### Effort Summary

| Batch | Name | Complexity | Status |
|-------|------|------------|--------|
| 1 | Auth & UI Foundation | Medium | ✅ Done |
| 2 | Infrastructure Config | Medium | ✅ Done |
| 3 | Commands & Templates | Low-Medium | ✅ Done |
| 4 | Orchestrator Core | High | ✅ Done |
| 5 | Advanced Targets | Medium | ✅ Done |
| 6 | Playwright Settings | Low-Medium | ✅ Done |
| 7 | Playwright Projects | Medium-High | ✅ Done |
| 8 | Playwright Execution | Medium-High | ✅ Done |
| 9 | Audit & History | Low-Medium | ✅ Done |
| 10 | App Framework | Medium-High | ✅ Done |
| **Subtotal (1-10)** | | | **✅ Complete** |
| 11 | Security Hardening | Medium | ✅ Done |
| 12 | Advanced Auth | High | 🔴 ~4-5 days |
| 13 | Doki Sources | Medium-High | 🟡 ~1-2 days remaining |
| 14 | Kubernetes | Medium-High | 🟡 ~2-3 days remaining |
| 15 | Onboarding & Bootstrap | Medium | 🔴 ~2-3 days |
| **Subtotal (11-15)** | | | **~9-13 days** |
