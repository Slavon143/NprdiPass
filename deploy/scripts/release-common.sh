#!/usr/bin/env bash
# Sourced by deployment/rollback. All hooks receive the verified application root.
set -Eeuo pipefail

fail() { echo "ERROR: $*" >&2; exit 2; }

validate_release_operation() {
    [[ "$RELEASE_ID" =~ ^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$ ]] || fail "Invalid release ID."
    [[ -d "$APP_ROOT" ]] || fail "Application root must already exist."
    APP_ROOT=$(realpath -e "$APP_ROOT")
    [[ "$APP_ROOT" != / ]] || fail "Application root cannot be '/'."
    RELEASES_DIR="$APP_ROOT/releases"
    SHARED_DIR="$APP_ROOT/shared"
    ARCHIVE_DIR="$APP_ROOT/artifacts"
    CURRENT_LINK="$APP_ROOT/current"
    RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"
    [[ ! -L "$RELEASES_DIR" && ! -L "$ARCHIVE_DIR" ]] || fail "Release/archive directories must not be symlinks."
    for hook in "$PAUSE_WRITERS" "$VERIFY_STOPPED" "$RESUME_WRITERS" "$VERIFY_BACKUP"; do
        [[ "$hook" = /* && -f "$hook" && -x "$hook" ]] || fail "An absolute executable path is required for every lifecycle hook."
    done
    [[ "$HEALTH_URL" =~ ^https?:// ]] || fail "A health URL is required."
    [[ -f "$SHARED_DIR/.env" && -d "$SHARED_DIR/storage/app" && -d "$SHARED_DIR/storage/framework" && -d "$SHARED_DIR/storage/logs" ]] || fail "Shared environment and storage must already exist."
    if [[ -e "$CURRENT_LINK" && ! -L "$CURRENT_LINK" ]]; then fail "Current must be a release symlink."; fi
    if [[ -L "$CURRENT_LINK" ]]; then
        local current
        current=$(realpath -e "$CURRENT_LINK")
        [[ "$current" == "$RELEASES_DIR/"* && -d "$current" ]] || fail "Current release escaped the release directory."
    fi
}

lock_release_operation() {
    command -v flock >/dev/null || fail "flock is required."
    # Keep the inode: unlinking lets a third process bypass another holder's lock.
    exec 200>"$APP_ROOT/deploy.lock"
    flock -n 200 || { echo "ERROR: Another release operation is in progress." >&2; exit 3; }
    RESUMED=false
    trap release_exit EXIT
}

release_exit() {
    local status=$?
    trap - EXIT
    if (( status != 0 )); then
        if [[ "$RESUMED" == true ]]; then
            "$PAUSE_WRITERS" "$APP_ROOT" && "$VERIFY_STOPPED" "$APP_ROOT" || echo "ERROR: Could not verify stopped writers after failure." >&2
        fi
        echo "ERROR: Release incomplete. Inspect current code/schema and writer state before resuming. No automatic database rollback was attempted." >&2
    fi
    exit "$status"
}

pause_and_verify() {
    "$PAUSE_WRITERS" "$APP_ROOT"
    "$VERIFY_STOPPED" "$APP_ROOT"
    "$VERIFY_BACKUP" "$APP_ROOT"
}

build_release_caches() {
    php "$RELEASE_DIR/artisan" optimize:clear
    php "$RELEASE_DIR/artisan" config:cache
    php "$RELEASE_DIR/artisan" route:cache
    php "$RELEASE_DIR/artisan" view:cache
}

activate_and_check() {
    ln -sfn "$RELEASE_DIR" "$CURRENT_LINK.new"
    mv -T "$CURRENT_LINK.new" "$CURRENT_LINK"
    # The site hook starts fresh processes with the new current path; queue:restart
    # by itself neither drains writers nor proves a supervisor restarted them.
    RESUMED=true
    "$RESUME_WRITERS" "$APP_ROOT"
    local endpoint okay attempt
    for endpoint in up ready; do
        okay=false
        for ((attempt=1; attempt<=10; attempt++)); do
            if curl --fail --silent --max-time 10 "$HEALTH_URL/$endpoint" > /dev/null; then okay=true; break; fi
            sleep 2
        done
        [[ "$okay" == true ]] || { echo "ERROR: /$endpoint health check failed." >&2; return 5; }
    done
}
