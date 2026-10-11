#!/usr/bin/env bash
set -Eeuo pipefail
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$SCRIPT_DIR/release-common.sh"
RELEASE_ID="" ARTIFACT="" CHECKSUM_FILE="" APP_ROOT="" HEALTH_URL=""
PAUSE_WRITERS="" VERIFY_STOPPED="" RESUME_WRITERS="" VERIFY_BACKUP="" VERIFY_RELEASE=""
DRY_RUN=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --release-id|--artifact|--checksum-file|--app-root|--health-url|--pause-writers|--verify-writers-stopped|--resume-writers|--verify-backup|--verify-release)
            [[ $# -ge 2 && -n "$2" ]] || fail "Missing value for $1."
            case "$1" in
                --release-id) RELEASE_ID="$2";; --artifact) ARTIFACT="$2";; --checksum-file) CHECKSUM_FILE="$2";;
                --app-root) APP_ROOT="$2";; --health-url) HEALTH_URL="${2%/}";; --pause-writers) PAUSE_WRITERS="$2";;
                --verify-writers-stopped) VERIFY_STOPPED="$2";; --resume-writers) RESUME_WRITERS="$2";; --verify-backup) VERIFY_BACKUP="$2";;
                --verify-release) VERIFY_RELEASE="$2";;
            esac
            shift 2;;
        --dry-run) DRY_RUN=true; shift;;
        *) fail "Unknown option: $1";;
    esac
done
validate_release_operation
[[ -f "$ARTIFACT" && -f "$CHECKSUM_FILE" ]] || fail "Artifact and its checksum file are required."
[[ ! -e "$RELEASE_DIR" && ! -L "$RELEASE_DIR" ]] || fail "Release ID already exists."
python3 "$SCRIPT_DIR/release_artifact.py" verify --artifact "$ARTIFACT" --checksum-file "$CHECKSUM_FILE"
if [[ "$DRY_RUN" == true ]]; then
    echo "Verified plan: retain executable; pause/drain writers; verify backup; migrate; verify schema/counters; activate $RELEASE_ID; restart and check health."
    exit 0
fi
lock_release_operation
RETAINED=$(python3 "$SCRIPT_DIR/release_artifact.py" retain --artifact "$ARTIFACT" --checksum-file "$CHECKSUM_FILE" --archive-root "$ARCHIVE_DIR")
pause_and_verify
mkdir -p "$RELEASES_DIR"
mkdir "$RELEASE_DIR"
python3 "$SCRIPT_DIR/release_artifact.py" verify --artifact "$RETAINED/release.tar.gz" --checksum-file "$RETAINED/release.tar.gz.sha256"
tar -xzf "$RETAINED/release.tar.gz" -C "$RELEASE_DIR" --strip-components=1 --no-same-owner --no-same-permissions
basename "$RETAINED" > "$RELEASE_DIR/ARTIFACT_SHA256"
python3 "$SCRIPT_DIR/release_artifact.py" verify-tree --release-dir "$RELEASE_DIR" --archive-root "$ARCHIVE_DIR"
ln -s "$SHARED_DIR/.env" "$RELEASE_DIR/.env"
for directory in app framework logs; do
    rm -rf -- "$RELEASE_DIR/storage/$directory"
    ln -s "$SHARED_DIR/storage/$directory" "$RELEASE_DIR/storage/$directory"
done
"$VERIFY_RELEASE" "$APP_ROOT" "$RELEASE_DIR"
php "$RELEASE_DIR/artisan" migrate --force
php "$RELEASE_DIR/artisan" release:verify-schema
php "$RELEASE_DIR/artisan" catalog:capacity-reconcile --all-companies --repair
php "$RELEASE_DIR/artisan" catalog:capacity-reconcile --all-companies
php "$RELEASE_DIR/artisan" storage:link --force
build_release_caches
activate_and_check
# Working directories and content-addressed executable archives are retained.
# Pruning needs a reviewed retention policy covering historical DPP provenance.
echo "Deployment completed: $RELEASE_ID"
