#!/usr/bin/env python3

import datetime as dt
import hashlib
import html
import json
import os
import re
import shutil
import sqlite3
import subprocess
import tempfile
import threading
import time
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


REPO_ROOT = Path(os.environ.get("DOKI_REPO_ROOT", "/workspace")).resolve()
HOST_ROOT = os.environ.get("DOKI_HOST_ROOT") or str(REPO_ROOT)
CONFIGURED_COMPOSE_PROJECT = (os.environ.get("DOKI_COMPOSE_PROJECT_NAME") or os.environ.get("COMPOSE_PROJECT_NAME") or "").strip()
PORT = int(os.environ.get("DOKI_UPDATE_PORT", "8100"))
TOKEN_FILE = Path(os.environ.get("DOKI_UPDATE_TOKEN_FILE", str(REPO_ROOT / "app/data/update-helper/tokens.json")))
STATE_FILE = Path(os.environ.get("DOKI_UPDATE_STATE_FILE", str(REPO_ROOT / "app/data/update-helper/state.json")))
DB_PATH = Path(os.environ.get("DOKI_DB_PATH", str(REPO_ROOT / "app/data/doki.db")))
APP_HEALTH_URL = os.environ.get("DOKI_APP_HEALTH_URL", "http://php-app/")
CORE_SERVICES = [s.strip() for s in os.environ.get("DOKI_UPDATE_CORE_SERVICES", "php-app,go-orchestrator").split(",") if s.strip()]
REMOTE = os.environ.get("DOKI_UPDATE_REMOTE", "origin")
COMPOSE_PROJECT_CACHE = {"value": None}

SEMVER_RE = re.compile(r"^v(?P<major>0|[1-9]\d*)\.(?P<minor>0|[1-9]\d*)\.(?P<patch>0|[1-9]\d*)$")
JOB_LOCK = threading.Lock()


class UpdateError(Exception):
    def __init__(self, message, details=None):
        super().__init__(message)
        self.details = details or {}


def now_iso():
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def ensure_parent(path):
    path.parent.mkdir(parents=True, exist_ok=True)


def read_json(path, default):
    try:
        with path.open("r", encoding="utf-8") as handle:
            data = json.load(handle)
        return data if isinstance(data, dict) else default
    except FileNotFoundError:
        return default
    except Exception:
        return default


def write_json(path, data):
    ensure_parent(path)
    tmp = path.with_suffix(path.suffix + ".tmp")
    with tmp.open("w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2, sort_keys=True)
        handle.write("\n")
    tmp.replace(path)


def load_state():
    state = read_json(STATE_FILE, {})
    state.setdefault("job", None)
    state.setdefault("lastCheck", None)
    state.setdefault("lastDryRun", None)
    state.setdefault("history", [])
    return state


def save_state(state):
    write_json(STATE_FILE, state)


def update_state(**fields):
    with JOB_LOCK:
        state = load_state()
        state.update(fields)
        save_state(state)
        return state


def set_job(job):
    update_state(job=job)


def update_job(**fields):
    with JOB_LOCK:
        state = load_state()
        job = state.get("job") if isinstance(state.get("job"), dict) else {}
        job.update(fields)
        state["job"] = job
        save_state(state)
        return job


def append_job_log(message):
    with JOB_LOCK:
        state = load_state()
        job = state.get("job") if isinstance(state.get("job"), dict) else {}
        logs = job.get("logs") if isinstance(job.get("logs"), list) else []
        logs.append({"at": now_iso(), "message": message})
        job["logs"] = logs[-200:]
        state["job"] = job
        save_state(state)


def command_result(args, cwd=REPO_ROOT, timeout=60, env=None):
    started = time.time()
    try:
        completed = subprocess.run(
            args,
            cwd=str(cwd),
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=timeout,
            check=False,
        )
        stdout = completed.stdout.rstrip("\n")
        stderr = completed.stderr.rstrip("\n")
        output = "\n".join(part for part in [stdout, stderr] if part)
        return {
            "success": completed.returncode == 0,
            "exitCode": completed.returncode,
            "output": output,
            "durationSeconds": round(time.time() - started, 3),
        }
    except subprocess.TimeoutExpired as exc:
        output = "\n".join(part for part in [(exc.stdout or "").strip(), (exc.stderr or "").strip()] if part)
        return {
            "success": False,
            "exitCode": 124,
            "output": (output + "\n" if output else "") + f"Command timed out after {timeout}s",
            "durationSeconds": round(time.time() - started, 3),
        }
    except Exception as exc:
        return {
            "success": False,
            "exitCode": 1,
            "output": str(exc),
            "durationSeconds": round(time.time() - started, 3),
        }


def inspect_container_label(container, label):
    if not container:
        return ""
    template = f"{{{{ index .Config.Labels \"{label}\" }}}}"
    result = command_result(["docker", "inspect", "--format", template, container], timeout=10)
    if not result["success"]:
        return ""
    value = result["output"].strip()
    if not value or value == "<no value>":
        return ""
    return value


def compose_project_name():
    if COMPOSE_PROJECT_CACHE["value"] is not None:
        return COMPOSE_PROJECT_CACHE["value"]

    candidates = [
        CONFIGURED_COMPOSE_PROJECT,
        inspect_container_label(os.environ.get("HOSTNAME"), "com.docker.compose.project"),
        inspect_container_label("doki-update-helper", "com.docker.compose.project"),
        inspect_container_label("doki-main-app", "com.docker.compose.project"),
        inspect_container_label("doki-go-orchestrator", "com.docker.compose.project"),
        Path(HOST_ROOT).name,
    ]
    project = next((value for value in candidates if value), "")
    COMPOSE_PROJECT_CACHE["value"] = project
    return project


def compose_env():
    env = dict(os.environ)
    env["DOKI_HOST_ROOT"] = HOST_ROOT
    project = compose_project_name()
    if project:
        env["COMPOSE_PROJECT_NAME"] = project
    return env


def run_or_raise(args, cwd=REPO_ROOT, timeout=60):
    result = command_result(args, cwd=cwd, timeout=timeout)
    if not result["success"]:
        raise UpdateError(result["output"] or f"Command failed: {' '.join(args)}", {"command": args, "result": result})
    return result


def git_command(args):
    return ["git", "-c", f"safe.directory={REPO_ROOT}", *args]


def git(args, timeout=60):
    return command_result(git_command(args), timeout=timeout)


def git_or_raise(args, timeout=60):
    return run_or_raise(git_command(args), timeout=timeout)


def ensure_git_safe_directory():
    existing = command_result(["git", "config", "--global", "--get-all", "safe.directory"], timeout=10)
    if str(REPO_ROOT) not in existing.get("output", "").splitlines():
        command_result(["git", "config", "--global", "--add", "safe.directory", str(REPO_ROOT)], timeout=10)


def parse_semver_tag(tag):
    match = SEMVER_RE.match(tag.strip())
    if not match:
        return None
    return tuple(int(match.group(part)) for part in ("major", "minor", "patch"))


def version_to_tag(version):
    version = str(version or "").strip()
    if version.startswith("v"):
        return version
    return f"v{version}"


def tag_to_version(tag):
    return tag[1:] if tag.startswith("v") else tag


def read_text(path, default=""):
    try:
        return path.read_text(encoding="utf-8").strip()
    except Exception:
        return default


def current_version():
    return read_text(REPO_ROOT / "app/VERSION", "unknown")


def current_commit():
    result = git(["rev-parse", "HEAD"])
    return result["output"].strip() if result["success"] else None


def current_ref():
    result = git(["branch", "--show-current"])
    if result["success"] and result["output"].strip():
        return result["output"].strip()
    result = git(["describe", "--tags", "--exact-match"])
    if result["success"] and result["output"].strip():
        return result["output"].strip()
    return "detached"


def stable_tags(fetch=False):
    if fetch:
        git(["fetch", "--tags", REMOTE], timeout=120)
    result = git(["tag", "--list", "v*"])
    if not result["success"]:
        return []
    tags = []
    for line in result["output"].splitlines():
        tag = line.strip()
        semver = parse_semver_tag(tag)
        if semver is None:
            continue
        commit_result = git(["rev-parse", f"{tag}^{{}}"])
        tags.append({
            "tag": tag,
            "version": tag_to_version(tag),
            "semver": semver,
            "commit": commit_result["output"].strip() if commit_result["success"] else None,
        })
    tags.sort(key=lambda item: item["semver"])
    return tags


def latest_stable_tag(fetch=False):
    tags = stable_tags(fetch=fetch)
    return tags[-1] if tags else None


def git_show_json(ref, path):
    result = git(["show", f"{ref}:{path}"], timeout=60)
    if not result["success"] or not result["output"].strip():
        return None
    try:
        return json.loads(result["output"])
    except Exception:
        return None


def git_show_text(ref, path):
    result = git(["show", f"{ref}:{path}"], timeout=60)
    return result["output"] if result["success"] else ""


def release_from_manifest(ref, version):
    manifest = git_show_json(ref, "app/releases/manifest.json")
    if not isinstance(manifest, dict):
        return None
    for release in manifest.get("releases", []):
        if isinstance(release, dict) and str(release.get("version")) == str(version):
            return release
    return None


def extract_changelog_section(changelog, version):
    if not changelog:
        return ""
    pattern = re.compile(rf"^##\s+{re.escape(str(version))}\b.*$", re.MULTILINE)
    match = pattern.search(changelog)
    if not match:
        return ""
    next_match = re.search(r"^##\s+", changelog[match.end():], re.MULTILINE)
    end = match.end() + next_match.start() if next_match else len(changelog)
    return changelog[match.start():end].strip()


def changed_files(target_tag):
    result = git(["diff", "--name-only", "HEAD", target_tag, "--"], timeout=60)
    if not result["success"]:
        return []
    return [line.strip() for line in result["output"].splitlines() if line.strip()]


def requirements_from_changes(release, files):
    requirements = release.get("requirements") if isinstance(release, dict) else {}
    if not isinstance(requirements, dict):
        requirements = {}
    rebuild_paths = ("Dockerfile", "docker-compose", "orchestrator-go/", "docker/update-helper/")
    requires_rebuild = bool(requirements.get("requiresRebuild")) or any(
        path == "Dockerfile" or path.startswith(rebuild_paths) or path.endswith("/Dockerfile")
        for path in files
    )
    requires_restart = bool(requirements.get("requiresRestart")) or requires_rebuild or bool(files)
    return {
        "requiresRestart": requires_restart,
        "requiresRebuild": requires_rebuild,
        "requiresSetupCheck": bool(requirements.get("requiresSetupCheck")),
    }


def app_manifest_is_core(app_id):
    manifest = REPO_ROOT / "app/apps" / app_id / "manifest.yaml"
    if not manifest.exists():
        return None
    text = read_text(manifest, "")
    return re.search(r"^\s*core\s*:\s*true\s*$", text, re.IGNORECASE | re.MULTILINE) is not None


def classify_worktree_changes(target_tag=None):
    result = git(["status", "--porcelain=v1", "--untracked-files=all"])
    changes = []
    blockers = []
    child_apps = {}
    if not result["success"]:
        return {
            "clean": False,
            "changes": [],
            "blockingChanges": [{"path": "", "status": "!!", "reason": result["output"] or "Unable to inspect git status"}],
            "protectedChildApps": [],
        }

    for line in result["output"].splitlines():
        if not line:
            continue
        status = line[:2]
        path = line[3:].strip()
        if " -> " in path:
            path = path.split(" -> ", 1)[1]
        item = {"status": status, "path": path}
        changes.append(item)

        parts = path.split("/")
        is_child_app = len(parts) >= 3 and parts[0] == "app" and parts[1] == "apps"
        if status == "??" and is_child_app:
            app_id = parts[2]
            core = app_manifest_is_core(app_id)
            if core is False:
                child_apps[app_id] = {"id": app_id, "path": f"app/apps/{app_id}", "reason": "untracked non-core app"}
                continue

        blockers.append({**item, "reason": "local change blocks updates"})

    if target_tag:
        for app_id, child in child_apps.items():
            tree = git(["ls-tree", "-r", "--name-only", target_tag, "--", child["path"]], timeout=60)
            if tree["success"] and tree["output"].strip():
                blockers.append({
                    "status": "??",
                    "path": child["path"],
                    "reason": f"target release contains files for protected child app '{app_id}'",
                })

    return {
        "clean": len(blockers) == 0,
        "changes": changes,
        "blockingChanges": blockers,
        "protectedChildApps": list(child_apps.values()),
    }


def collect_health():
    health = {
        "app": {"ok": False, "url": APP_HEALTH_URL, "status": None, "error": None},
        "compose": {"ok": False, "services": CORE_SERVICES, "output": None, "error": None},
    }
    try:
        request = urllib.request.Request(APP_HEALTH_URL, headers={"User-Agent": "doki-update-helper"})
        with urllib.request.urlopen(request, timeout=2) as response:
            health["app"]["status"] = response.status
            health["app"]["ok"] = 200 <= response.status < 500
    except Exception as exc:
        health["app"]["error"] = str(exc)

    compose = command_result(["docker", "compose", "ps"], timeout=15, env=compose_env())
    health["compose"]["ok"] = compose["success"]
    if compose["success"]:
        health["compose"]["output"] = compose["output"]
    else:
        health["compose"]["error"] = compose["output"]
    return health


def build_repo_state():
    tags = stable_tags(fetch=False)
    latest = tags[-1] if tags else None
    return {
        "root": str(REPO_ROOT),
        "currentVersion": current_version(),
        "currentCommit": current_commit(),
        "currentRef": current_ref(),
        "latestStable": latest,
        "stableTags": [{k: v for k, v in tag.items() if k != "semver"} for tag in tags],
        "worktree": classify_worktree_changes(),
    }


def build_full_state():
    state = load_state()
    return {
        "success": True,
        "time": now_iso(),
        "repo": build_repo_state(),
        "health": collect_health(),
        "runtime": state,
    }


def build_update_check(target_version=None):
    git_or_raise(["fetch", "--tags", REMOTE], timeout=120)
    tags = stable_tags(fetch=False)
    if not tags:
        raise UpdateError("No stable release tags are available.")

    tag_info = None
    if target_version:
        wanted = version_to_tag(target_version)
        for tag in tags:
            if tag["tag"] == wanted or tag["version"] == str(target_version):
                tag_info = tag
                break
        if tag_info is None:
            raise UpdateError(f"Stable release {target_version} was not found.")
    else:
        tag_info = tags[-1]

    target_tag = tag_info["tag"]
    target_version = tag_info["version"]
    release = release_from_manifest(target_tag, target_version) or {
        "version": target_version,
        "tag": target_tag,
        "summary": "No release metadata is available for this tag.",
        "changes": [],
        "risk": {"destructive": False, "databaseMigrations": []},
    }
    changelog = extract_changelog_section(git_show_text(target_tag, "app/releases/CHANGELOG.md"), target_version)
    files = changed_files(target_tag)
    worktree = classify_worktree_changes(target_tag)
    risk = release.get("risk") if isinstance(release.get("risk"), dict) else {}
    requirements = requirements_from_changes(release, files)
    current = current_version()
    current_semver = parse_semver_tag(version_to_tag(current))
    target_semver = parse_semver_tag(target_tag)
    update_available = bool(current_semver and target_semver and target_semver > current_semver)

    check = {
        "checkedAt": now_iso(),
        "currentVersion": current,
        "currentCommit": current_commit(),
        "targetVersion": target_version,
        "targetTag": target_tag,
        "targetCommit": tag_info.get("commit"),
        "updateAvailable": update_available,
        "release": release,
        "changelog": changelog,
        "changedFiles": files,
        "requirements": requirements,
        "destructive": bool(risk.get("destructive")),
        "databaseMigrations": risk.get("databaseMigrations") if isinstance(risk.get("databaseMigrations"), list) else [],
        "destructiveChanges": risk.get("destructiveChanges") if isinstance(risk.get("destructiveChanges"), list) else [],
        "worktree": worktree,
        "blocked": not worktree["clean"],
        "blockers": worktree["blockingChanges"],
    }
    state = load_state()
    state["lastCheck"] = check
    save_state(state)
    return check


def sqlite_backup(src, dst):
    if not src.exists():
        return None
    ensure_parent(dst)
    source = sqlite3.connect(f"file:{src}?mode=ro", uri=True, timeout=30)
    try:
        target = sqlite3.connect(str(dst), timeout=30)
        try:
            source.backup(target)
        finally:
            target.close()
    finally:
        source.close()
    return str(dst)


def migration_command(app_root, mode):
    script = app_root / "scripts/migrate.php"
    if script.exists():
        return ["php", str(script), f"--mode={mode}"]
    code = (
        f"require {json.dumps(str(app_root / 'includes/Database.php'))}; "
        "$db = Database::getInstance(); "
        "$row = $db->query('SELECT MAX(version) AS version FROM schema_version')->fetch(); "
        "echo json_encode(['success'=>true,'appliedSchemaVersion'=>(int)($row['version'] ?? 0)]) . PHP_EOL;"
    )
    return ["php", "-d", "display_errors=1", "-r", code]


def run_migration(app_root, mode, timeout=300):
    result = command_result(migration_command(app_root, mode), cwd=app_root.parent, timeout=timeout)
    parsed = None
    if result["output"]:
        json_start = result["output"].find("{")
        if json_start >= 0:
            try:
                parsed = json.loads(result["output"][json_start:])
            except Exception:
                parsed = None
    result["json"] = parsed
    return result


def create_release_tree(target_tag):
    temp_dir = Path(tempfile.mkdtemp(prefix="doki-update-"))
    release_root = temp_dir / "release"
    release_root.mkdir(parents=True, exist_ok=True)
    archive_path = temp_dir / "release.tar"
    try:
        git_or_raise(["archive", "--format=tar", "-o", str(archive_path), target_tag], timeout=120)
        run_or_raise(["tar", "-xf", str(archive_path), "-C", str(release_root)], cwd=temp_dir, timeout=120)
        return temp_dir, release_root
    except Exception:
        shutil.rmtree(temp_dir, ignore_errors=True)
        raise


def run_migration_dry_run(target_tag):
    temp_dir, release_root = create_release_tree(target_tag)
    try:
        target_db = release_root / "app/data/doki.db"
        if DB_PATH.exists():
            sqlite_backup(DB_PATH, target_db)
        else:
            ensure_parent(target_db)
        result = run_migration(release_root / "app", "dry-run", timeout=600)
        return {
            "success": result["success"],
            "targetTag": target_tag,
            "output": result["output"],
            "result": result.get("json"),
            "tempRoot": str(release_root),
        }
    finally:
        shutil.rmtree(temp_dir, ignore_errors=True)


def create_live_db_backup(target_version):
    if not DB_PATH.exists():
        return None
    backup_dir = DB_PATH.parent / "db-backups"
    stamp = dt.datetime.now().strftime("%Y%m%d_%H%M%S")
    suffix = hashlib.sha256(os.urandom(16)).hexdigest()[:8]
    backup_path = backup_dir / f"doki.pre-update-v{target_version}.{stamp}.{suffix}.db"
    return sqlite_backup(DB_PATH, backup_path)


def audit(action, token_record, details):
    try:
        if not DB_PATH.exists():
            return
        conn = sqlite3.connect(str(DB_PATH), timeout=10)
        try:
            conn.execute(
                """
                INSERT INTO audit_log (user_id, username, action, resource_type, resource_id, details, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    token_record.get("createdBy"),
                    token_record.get("createdByUsername") or "update-helper",
                    action,
                    "doki_update",
                    details.get("targetVersion"),
                    json.dumps(details, sort_keys=True),
                    token_record.get("ip"),
                ),
            )
            conn.commit()
        finally:
            conn.close()
    except Exception:
        return


def write_maintenance(active, payload=None):
    path = REPO_ROOT / "app/data/update-helper/maintenance.json"
    data = {"active": active, "updatedAt": now_iso()}
    if payload:
        data.update(payload)
    write_json(path, data)


def stop_core_services():
    if CORE_SERVICES:
        return command_result(["docker", "compose", "stop", *CORE_SERVICES], timeout=180, env=compose_env())
    return {"success": True, "output": "", "exitCode": 0}


def start_core_services():
    if CORE_SERVICES:
        return command_result(["docker", "compose", "up", "-d", "--build", *CORE_SERVICES], timeout=900, env=compose_env())
    return {"success": True, "output": "", "exitCode": 0}


def run_dry_run_job(job, target_version, token_record):
    try:
        update_job(stage="fetching", progress=10)
        check = build_update_check(target_version)
        target_tag = check["targetTag"]
        if check["blocked"]:
            raise UpdateError("Local changes block the migration dry run.", {"blockers": check["blockers"]})

        append_job_log(f"Running migration dry run for {target_tag}.")
        update_job(stage="migration-dry-run", progress=45)
        dry = run_migration_dry_run(target_tag)
        if not dry["success"]:
            raise UpdateError("Migration dry run failed.", {"output": dry["output"], "result": dry.get("result")})

        dry_run = {
            "success": True,
            "targetVersion": check["targetVersion"],
            "targetTag": target_tag,
            "targetCommit": check["targetCommit"],
            "currentCommit": check["currentCommit"],
            "completedAt": now_iso(),
            "output": dry["output"],
            "result": dry.get("result"),
        }
        state = load_state()
        state["lastDryRun"] = dry_run
        save_state(state)
        audit("doki.update.migration_dry_run", token_record, dry_run)
        append_job_log("Migration dry run completed.")
        update_job(status="succeeded", stage="complete", progress=100, finishedAt=now_iso())
    except UpdateError as exc:
        append_job_log(str(exc))
        audit("doki.update.migration_dry_run_failed", token_record, {"targetVersion": target_version, "error": str(exc), **exc.details})
        update_job(status="failed", stage="failed", progress=100, error=str(exc), details=exc.details, finishedAt=now_iso())
    except Exception as exc:
        append_job_log(str(exc))
        audit("doki.update.migration_dry_run_failed", token_record, {"targetVersion": target_version, "error": str(exc)})
        update_job(status="failed", stage="failed", progress=100, error=str(exc), finishedAt=now_iso())


def run_apply_job(job, target_version, confirm_version, token_record):
    stopped = False
    backup_path = None
    try:
        update_job(stage="checking", progress=5)
        check = build_update_check(target_version)
        target_tag = check["targetTag"]
        target_version = check["targetVersion"]
        if check["blocked"]:
            raise UpdateError("Local changes block this update.", {"blockers": check["blockers"]})
        if check["destructive"] and confirm_version not in [target_version, target_tag]:
            raise UpdateError("Destructive update confirmation did not match the target version.")

        audit("doki.update.apply_started", token_record, {"targetVersion": target_version, "targetTag": target_tag})

        append_job_log("Running mandatory migration dry run before apply.")
        update_job(stage="migration-dry-run", progress=20)
        dry = run_migration_dry_run(target_tag)
        if not dry["success"]:
            raise UpdateError("Migration dry run failed; real update was not started.", {"output": dry["output"], "result": dry.get("result")})

        update_job(stage="backup", progress=35)
        backup_path = create_live_db_backup(target_version)
        append_job_log(f"Database backup: {backup_path or 'no database present'}.")

        write_maintenance(True, {"targetVersion": target_version, "stage": "stopping-services"})
        update_job(stage="stopping-services", progress=45)
        stop_result = stop_core_services()
        stopped = True
        if not stop_result["success"]:
            append_job_log("Stopping services failed; continuing cautiously: " + stop_result["output"])

        update_job(stage="checkout", progress=58)
        append_job_log(f"Checking out {target_tag}.")
        git_or_raise(["checkout", "--detach", target_tag], timeout=180)

        update_job(stage="migration-apply", progress=72)
        append_job_log("Running real migration against live database.")
        migration = run_migration(REPO_ROOT / "app", "apply", timeout=900)
        if not migration["success"]:
            raise UpdateError("Real migration failed after checkout.", {"output": migration["output"], "backupPath": backup_path})

        update_job(stage="starting-services", progress=88)
        start_result = start_core_services()
        if not start_result["success"]:
            raise UpdateError("Updated files and migrations succeeded, but service restart failed.", {"output": start_result["output"], "backupPath": backup_path})
        stopped = False

        history_entry = {
            "fromVersion": check["currentVersion"],
            "fromCommit": check["currentCommit"],
            "toVersion": target_version,
            "toTag": target_tag,
            "toCommit": check["targetCommit"],
            "backupPath": backup_path,
            "updatedAt": now_iso(),
        }
        state = load_state()
        history = state.get("history") if isinstance(state.get("history"), list) else []
        state["history"] = [history_entry, *history][:20]
        state["lastDryRun"] = {
            "success": True,
            "targetVersion": target_version,
            "targetTag": target_tag,
            "targetCommit": check["targetCommit"],
            "currentCommit": check["currentCommit"],
            "completedAt": now_iso(),
            "output": dry["output"],
            "result": dry.get("result"),
        }
        save_state(state)
        write_maintenance(False, {"targetVersion": target_version, "stage": "complete"})
        audit("doki.update.apply_succeeded", token_record, history_entry)
        append_job_log("Update completed.")
        update_job(status="succeeded", stage="complete", progress=100, result=history_entry, finishedAt=now_iso())
    except UpdateError as exc:
        append_job_log(str(exc))
        write_maintenance(False, {"targetVersion": target_version, "stage": "failed", "error": str(exc), "backupPath": backup_path})
        audit("doki.update.apply_failed", token_record, {"targetVersion": target_version, "error": str(exc), "backupPath": backup_path, **exc.details})
        if stopped:
            append_job_log("Attempting to start core services after failure.")
            start_core_services()
        update_job(status="failed", stage="failed", progress=100, error=str(exc), details=exc.details, backupPath=backup_path, finishedAt=now_iso())
    except Exception as exc:
        append_job_log(str(exc))
        write_maintenance(False, {"targetVersion": target_version, "stage": "failed", "error": str(exc), "backupPath": backup_path})
        audit("doki.update.apply_failed", token_record, {"targetVersion": target_version, "error": str(exc), "backupPath": backup_path})
        if stopped:
            append_job_log("Attempting to start core services after failure.")
            start_core_services()
        update_job(status="failed", stage="failed", progress=100, error=str(exc), backupPath=backup_path, finishedAt=now_iso())


def start_job(kind, target_version, token_record, confirm_version=None):
    with JOB_LOCK:
        state = load_state()
        active = state.get("job")
        if isinstance(active, dict) and active.get("status") == "running":
            raise UpdateError("An update job is already running.", {"job": active})
        job = {
            "id": hashlib.sha256(os.urandom(16)).hexdigest()[:16],
            "kind": kind,
            "targetVersion": target_version,
            "status": "running",
            "stage": "queued",
            "progress": 0,
            "startedAt": now_iso(),
            "logs": [],
        }
        state["job"] = job
        save_state(state)

    target = run_dry_run_job if kind == "migration-dry-run" else run_apply_job
    args = (job, target_version, token_record) if kind == "migration-dry-run" else (job, target_version, confirm_version, token_record)
    thread = threading.Thread(target=target, args=args, daemon=True)
    thread.start()
    return job


def token_hash(token):
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def parse_iso(value):
    try:
        return dt.datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except Exception:
        return None


def validate_token(token):
    if not token:
        return None
    data = read_json(TOKEN_FILE, {})
    records = data.get("tokens") if isinstance(data.get("tokens"), list) else []
    hashed = token_hash(token)
    now = dt.datetime.now(dt.timezone.utc)
    for record in records:
        if not isinstance(record, dict) or record.get("hash") != hashed:
            continue
        expires = parse_iso(record.get("expiresAt"))
        if expires and expires > now:
            return record
    return None


def page_html():
    return """<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Doki Update Helper</title>
  <style>
    :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    body { margin: 0; background: #0d1117; color: #e6edf3; }
    main { max-width: 1180px; margin: 0 auto; padding: 28px; }
    header { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; margin-bottom: 22px; }
    h1 { margin: 0; font-size: 28px; }
    h2 { margin: 0 0 12px; font-size: 15px; }
    p { color: #9da7b3; line-height: 1.5; }
    .grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 14px; }
    .panel { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 16px; min-width: 0; }
    .span-4 { grid-column: span 4; }
    .span-6 { grid-column: span 6; }
    .span-8 { grid-column: span 8; }
    .span-12 { grid-column: span 12; }
    .kv { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 8px 12px; font-size: 14px; }
    .key { color: #9da7b3; }
    .value { overflow-wrap: anywhere; }
    .badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 9px; font-size: 12px; border: 1px solid #30363d; background: #0d1117; }
    .ok { color: #7ee787; }
    .warn { color: #ffa657; }
    .bad { color: #ff7b72; }
    button, select, input { border-radius: 7px; border: 1px solid #30363d; background: #21262d; color: #e6edf3; font: inherit; padding: 9px 11px; }
    button { cursor: pointer; font-weight: 650; }
    button.primary { background: #238636; border-color: #2ea043; }
    button.danger { background: #da3633; border-color: #f85149; }
    button:disabled { opacity: .55; cursor: not-allowed; }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    pre { white-space: pre-wrap; word-break: break-word; background: #0d1117; border: 1px solid #30363d; padding: 12px; border-radius: 8px; max-height: 300px; overflow: auto; }
    ul { margin: 0; padding-left: 20px; }
    li { margin: 4px 0; color: #c9d1d9; }
    .muted { color: #9da7b3; }
    .progress { height: 9px; border-radius: 999px; background: #30363d; overflow: hidden; }
    .progress div { height: 100%; width: 0; background: #58a6ff; transition: width .2s ease; }
    @media (max-width: 900px) { .span-4, .span-6, .span-8 { grid-column: span 12; } header { flex-direction: column; } main { padding: 18px; } }
  </style>
</head>
<body>
<main>
  <header>
    <div>
      <h1>Doki Update Helper</h1>
      <p>Stable-tag updates, migration dry runs, health checks, and service restart status.</p>
    </div>
    <div class="badge" id="authStatus">Checking access</div>
  </header>
  <section class="grid">
    <div class="panel span-4">
      <h2>Current</h2>
      <div class="kv" id="currentState"></div>
    </div>
    <div class="panel span-4">
      <h2>Health</h2>
      <div class="kv" id="healthState"></div>
    </div>
    <div class="panel span-4">
      <h2>Target</h2>
      <div class="actions">
        <select id="targetSelect"></select>
        <button id="checkBtn">Check</button>
      </div>
      <p class="muted" id="targetHint"></p>
    </div>
    <div class="panel span-8">
      <h2>Release Review</h2>
      <div id="releaseReview" class="muted">Run a check to review the next stable release.</div>
    </div>
    <div class="panel span-4">
      <h2>Actions</h2>
      <div class="actions">
        <button id="dryRunBtn">Migration Dry Run</button>
        <input id="confirmInput" placeholder="Confirm version">
        <button id="applyBtn" class="danger">Apply Update</button>
      </div>
      <p class="muted">Apply always runs a fresh dry run first and creates a database backup before changing code.</p>
    </div>
    <div class="panel span-12">
      <h2>Job</h2>
      <div class="progress"><div id="jobProgress"></div></div>
      <div id="jobState" class="muted" style="margin-top:10px;"></div>
      <pre id="jobLog"></pre>
    </div>
  </section>
</main>
<script>
const params = new URLSearchParams(location.search);
const token = params.get('token') || '';
let latestState = null;

function el(id) { return document.getElementById(id); }
function text(value) { return value === null || value === undefined || value === '' ? 'n/a' : String(value); }
function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
async function api(path, options = {}) {
  const init = { ...options, headers: { 'X-Doki-Update-Token': token, ...(options.headers || {}) } };
  if (init.body && typeof init.body !== 'string') {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(init.body);
  }
  const response = await fetch(path, init);
  const data = await response.json().catch(() => ({ success: false, error: 'Invalid JSON response' }));
  if (!response.ok || data.success === false) throw new Error(data.error || response.statusText);
  return data;
}
function renderKv(id, rows) {
  el(id).innerHTML = rows.map(([k, v, cls]) => `<div class="key">${esc(k)}</div><div class="value ${cls || ''}">${esc(v)}</div>`).join('');
}
function renderState(data) {
  latestState = data;
  el('authStatus').textContent = 'Authorized';
  el('authStatus').className = 'badge ok';
  const repo = data.repo || {};
  const health = data.health || {};
  renderKv('currentState', [
    ['Version', repo.currentVersion],
    ['Ref', repo.currentRef],
    ['Commit', repo.currentCommit ? repo.currentCommit.slice(0, 12) : 'n/a'],
    ['Worktree', repo.worktree?.clean ? 'clean' : 'blocked', repo.worktree?.clean ? 'ok' : 'bad'],
    ['Latest stable', repo.latestStable?.tag || 'n/a']
  ]);
  renderKv('healthState', [
    ['Doki app', health.app?.ok ? `ok (${health.app.status})` : (health.app?.error || 'down'), health.app?.ok ? 'ok' : 'warn'],
    ['Compose', health.compose?.ok ? 'reachable' : (health.compose?.error || 'unavailable'), health.compose?.ok ? 'ok' : 'warn']
  ]);
  const select = el('targetSelect');
  const existing = select.value;
  select.innerHTML = (repo.stableTags || []).map(t => `<option value="${esc(t.version)}">${esc(t.tag)}</option>`).join('');
  if (existing) select.value = existing;
  else if (repo.latestStable?.version) select.value = repo.latestStable.version;

  const check = data.runtime?.lastCheck;
  if (check) renderReview(check);
  renderJob(data.runtime?.job);
}
function renderReview(check) {
  const release = check.release || {};
  const migrations = check.databaseMigrations || [];
  const blockers = check.blockers || [];
  const changed = check.changedFiles || [];
  el('targetHint').textContent = check.updateAvailable ? `Update available: ${check.currentVersion} -> ${check.targetVersion}` : `No newer stable release than ${check.currentVersion}.`;
  el('releaseReview').innerHTML = `
    <div class="kv">
      <div class="key">Target</div><div class="value">${esc(check.targetTag)} (${esc((check.targetCommit || '').slice(0, 12))})</div>
      <div class="key">Summary</div><div class="value">${esc(release.summary || 'No summary')}</div>
      <div class="key">Restart</div><div class="value ${check.requirements?.requiresRestart ? 'warn' : 'ok'}">${check.requirements?.requiresRestart ? 'required' : 'not required'}</div>
      <div class="key">Rebuild</div><div class="value ${check.requirements?.requiresRebuild ? 'warn' : 'ok'}">${check.requirements?.requiresRebuild ? 'required' : 'not required'}</div>
      <div class="key">Destructive</div><div class="value ${check.destructive ? 'bad' : 'ok'}">${check.destructive ? 'yes' : 'no'}</div>
      <div class="key">Blocked</div><div class="value ${check.blocked ? 'bad' : 'ok'}">${check.blocked ? 'yes' : 'no'}</div>
    </div>
    <h2 style="margin-top:16px;">Migrations</h2>
    ${migrations.length ? `<ul>${migrations.map(m => `<li>${esc(m.scope || 'global')}: ${esc(m.summary || '')}</li>`).join('')}</ul>` : '<p class="muted">No migrations declared.</p>'}
    <h2 style="margin-top:16px;">Blockers</h2>
    ${blockers.length ? `<ul>${blockers.map(b => `<li>${esc(b.status)} ${esc(b.path)} - ${esc(b.reason)}</li>`).join('')}</ul>` : '<p class="muted">No blocking local changes.</p>'}
    <h2 style="margin-top:16px;">Changed Files</h2>
    ${changed.length ? `<pre>${esc(changed.slice(0, 120).join('\\n'))}</pre>` : '<p class="muted">No file changes for this target.</p>'}
    ${check.changelog ? `<h2 style="margin-top:16px;">Changelog</h2><pre>${esc(check.changelog)}</pre>` : ''}
  `;
}
function renderJob(job) {
  if (!job) {
    el('jobProgress').style.width = '0%';
    el('jobState').textContent = 'No job has run yet.';
    el('jobLog').textContent = '';
    return;
  }
  el('jobProgress').style.width = `${job.progress || 0}%`;
  el('jobState').innerHTML = `${esc(job.kind)} / ${esc(job.stage)} / <span class="${job.status === 'failed' ? 'bad' : job.status === 'succeeded' ? 'ok' : 'warn'}">${esc(job.status)}</span>${job.error ? ': ' + esc(job.error) : ''}`;
  el('jobLog').textContent = (job.logs || []).map(l => `${l.at} ${l.message}`).join('\\n');
}
async function refresh() {
  if (!token) {
    el('authStatus').textContent = 'Missing token';
    el('authStatus').className = 'badge bad';
    return;
  }
  try {
    const data = await api('/api/state');
    renderState(data);
  } catch (err) {
    el('authStatus').textContent = err.message;
    el('authStatus').className = 'badge bad';
  }
}
async function postAction(path, body) {
  try {
    const data = await api(path, { method: 'POST', body });
    renderState(data.state || data);
  } catch (err) {
    alert(err.message);
  }
}
el('checkBtn').addEventListener('click', () => postAction('/api/check', { targetVersion: el('targetSelect').value }));
el('dryRunBtn').addEventListener('click', () => postAction('/api/migration-dry-run', { targetVersion: el('targetSelect').value }));
el('applyBtn').addEventListener('click', () => postAction('/api/apply', { targetVersion: el('targetSelect').value, confirmVersion: el('confirmInput').value.trim() }));
refresh();
setInterval(refresh, 3000);
</script>
</body>
</html>"""


class Handler(BaseHTTPRequestHandler):
    server_version = "DokiUpdateHelper/1.0"

    def log_message(self, fmt, *args):
        return

    def send_json(self, data, status=200):
        body = json.dumps(data, indent=2, sort_keys=True).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def send_html(self, content):
        body = content.encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def read_body(self):
        length = int(self.headers.get("Content-Length") or "0")
        if length <= 0:
            return {}
        raw = self.rfile.read(length).decode("utf-8")
        try:
            data = json.loads(raw)
            return data if isinstance(data, dict) else {}
        except Exception:
            raise UpdateError("Invalid JSON body.")

    def request_token_record(self):
        parsed = urllib.parse.urlparse(self.path)
        params = urllib.parse.parse_qs(parsed.query)
        token = self.headers.get("X-Doki-Update-Token", "")
        auth = self.headers.get("Authorization", "")
        if not token and auth.startswith("Bearer "):
            token = auth[7:].strip()
        if not token:
            token = (params.get("token") or [""])[0]
        record = validate_token(token)
        if record is None:
            raise UpdateError("Update helper access token is missing or expired.")
        return record

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path == "/":
            self.send_html(page_html())
            return
        if parsed.path == "/api/state":
            try:
                self.request_token_record()
                self.send_json(build_full_state())
            except UpdateError as exc:
                self.send_json({"success": False, "error": str(exc), "details": exc.details}, 403)
            return
        self.send_json({"success": False, "error": "Not found"}, 404)

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        try:
            record = self.request_token_record()
            body = self.read_body()
            if parsed.path == "/api/check":
                check = build_update_check(body.get("targetVersion"))
                self.send_json({"success": True, "check": check, "state": build_full_state()})
                return
            if parsed.path == "/api/migration-dry-run":
                job = start_job("migration-dry-run", body.get("targetVersion"), record)
                self.send_json({"success": True, "job": job, "state": build_full_state()})
                return
            if parsed.path == "/api/apply":
                job = start_job("apply", body.get("targetVersion"), record, body.get("confirmVersion"))
                self.send_json({"success": True, "job": job, "state": build_full_state()})
                return
            self.send_json({"success": False, "error": "Not found"}, 404)
        except UpdateError as exc:
            self.send_json({"success": False, "error": str(exc), "details": exc.details}, 400)
        except Exception as exc:
            self.send_json({"success": False, "error": str(exc)}, 500)


def main():
    ensure_git_safe_directory()
    ensure_parent(STATE_FILE)
    ensure_parent(TOKEN_FILE)
    server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    print(f"Doki update helper listening on 0.0.0.0:{PORT}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
