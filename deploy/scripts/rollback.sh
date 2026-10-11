#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$SCRIPT_DIR/release-common.sh"
RELEASE_ID="" APP_ROOT="" HEALTH_URL=""
PAUSE_WRITERS="" VERIFY_STOPPED="" RESUME_WRITERS="" VERIFY_BACKUP="" VERIFY_RELEASE=""
DRY_RUN=false FORCE=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --release-id|--app-root|--health-url|--pause-writers|--verify-writers-stopped|--resume-writers|--verify-backup|--verify-release)
            [[ $# -ge 2 && -n "$2" ]] || fail "Missing value for $1."
            case "$1" in
                --release-id) RELEASE_ID="$2";; --app-root) APP_ROOT="$2";; --health-url) HEALTH_URL="${2%/}";;
                --pause-writers) PAUSE_WRITERS="$2";; --verify-writers-stopped) VERIFY_STOPPED="$2";;
                --resume-writers) RESUME_WRITERS="$2";; --verify-backup) VERIFY_BACKUP="$2";;
                --verify-release) VERIFY_RELEASE="$2";;
            esac
            shift 2;;
        --dry-run) DRY_RUN=true; shift;; --force) FORCE=true; shift;;
        *) fail "Unknown option: $1";;
    esac
done
validate_release_operation
[[ -L "$CURRENT_LINK" && -d "$RELEASE_DIR" && ! -L "$RELEASE_DIR" ]] || fail "Current and explicit rollback release are required."
[[ $(realpath -e "$CURRENT_LINK") != "$RELEASE_DIR" ]] || fail "Rollback release is already current."
python3 "$SCRIPT_DIR/release_artifact.py" verify-tree --release-dir "$RELEASE_DIR" --archive-root "$ARCHIVE_DIR"
# Read-only site policy must reject unsafe historical integrations before pause.
"$VERIFY_RELEASE" "$APP_ROOT" "$RELEASE_DIR"
if [[ "$DRY_RUN" == true ]]; then
    echo "Verified plan: pause/drain writers; verify backup and rollback code/schema compatibility; activate $RELEASE_ID; restart and check health. Database migrations are not reverted."
    exit 0
fi
[[ "$FORCE" == true ]] || fail "Rollback requires --force."
lock_release_operation
"$VERIFY_RELEASE" "$APP_ROOT" "$RELEASE_DIR"
pause_and_verify
# Refuse releases without this guard or with a different migration/capacity
# contract BEFORE switching. A schema rollback needs its own coordinated plan.
php "$RELEASE_DIR/artisan" release:verify-schema
php "$RELEASE_DIR/artisan" catalog:capacity-reconcile --all-companies
build_release_caches
activate_and_check
echo "Rollback completed: $RELEASE_ID. Database migrations were not reverted."
