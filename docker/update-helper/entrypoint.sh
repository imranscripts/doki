#!/usr/bin/env sh
set -eu

repo_root="${DOKI_REPO_ROOT:-/workspace}"

if ! git config --global --get-all safe.directory 2>/dev/null | grep -Fx "$repo_root" >/dev/null 2>&1; then
    git config --global --add safe.directory "$repo_root"
fi

exec "$@"
