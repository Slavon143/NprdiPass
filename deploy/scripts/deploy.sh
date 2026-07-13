#!/usr/bin/env bash

set -Eeuo pipefail

# --- Parse arguments ---
RELEASE_ID=""
ARTIFACT=""
CHECKSUM_FILE=""
APP_ROOT=""
HEALTH_URL=""
DRY_RUN=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --release-id)    RELEASE_ID="$2"; shift 2 ;;
        --artifact)      ARTIFACT="$2"; shift 2 ;;
        --checksum-file) CHECKSUM_FILE="$2"; shift 2 ;;
        --app-root)      APP_ROOT="$2"; shift 2 ;;
        --health-url)    HEALTH_URL="$2"; shift 2 ;;
        --dry-run)       DRY_RUN=true; shift ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

# --- Validate required arguments ---
if [ -z "$RELEASE_ID" ] || [ -z "$ARTIFACT" ] || [ -z "$APP_ROOT" ]; then
    echo "Usage: $0 --release-id=<id> --artifact=<file> --app-root=<path> [--checksum-file=<file>] [--health-url=<url>] [--dry-run]"
    exit 2
fi

RELEASES_DIR="${APP_ROOT}/releases"
SHARED_DIR="${APP_ROOT}/shared"
CURRENT_LINK="${APP_ROOT}/current"
RELEASE_DIR="${RELEASES_DIR}/${RELEASE_ID}"

# --- Dry-run mode ---
if [ "$DRY_RUN" = true ]; then
    echo "--- Dry Run ---"
    echo "Release ID:      $RELEASE_ID"
    echo "Artifact:        $ARTIFACT"
    echo "App root:        $APP_ROOT"
    echo "Release dir:     $RELEASE_DIR"
    echo "Current link:    $CURRENT_LINK"
    echo "Health URL:      ${HEALTH_URL:-not set}"
    echo "Checksum file:   ${CHECKSUM_FILE:-not set}"
    echo "---"
    exit 0
fi

# --- Acquire deployment lock ---
LOCK_FILE="${APP_ROOT}/deploy.lock"
exec 200>"${LOCK_FILE}"
if ! flock -n 200; then
    echo "ERROR: Another deployment is in progress."
    exit 3
fi
trap 'flock -u 200; rm -f "${LOCK_FILE}"' EXIT

# --- Validate paths ---
if [ "${APP_ROOT}" = "/" ]; then
    echo "ERROR: Application root cannot be '/'."
    exit 2
fi

if [ -z "${RELEASE_ID##*/*}" ]; then
    echo "ERROR: Invalid release ID."
    exit 2
fi

# --- Verify artifact checksum ---
if [ -n "$CHECKSUM_FILE" ] && [ -f "$CHECKSUM_FILE" ]; then
    echo "Verifying artifact checksum..."
    if ! sha256sum -c "$CHECKSUM_FILE"; then
        echo "ERROR: Artifact checksum mismatch."
        exit 4
    fi
fi

# --- Create release directory ---
echo "Creating release directory: ${RELEASE_DIR}"
mkdir -p "${RELEASE_DIR}"

# --- Extract artifact ---
echo "Extracting artifact..."
tar -xzf "$ARTIFACT" -C "${RELEASE_DIR}" --strip-components=1
rm -f "$ARTIFACT" "${CHECKSUM_FILE}"

# --- Verify RELEASE.json ---
if [ ! -f "${RELEASE_DIR}/RELEASE.json" ]; then
    echo "ERROR: RELEASE.json not found in artifact."
    rm -rf "${RELEASE_DIR}"
    exit 4
fi

echo "Release: $(jq -r '.ref // "unknown"' "${RELEASE_DIR}/RELEASE.json" 2>/dev/null || cat "${RELEASE_DIR}/RELEASE.json")"

# --- Link shared files ---
if [ -d "${SHARED_DIR}" ]; then
    echo "Linking shared files..."

    if [ -f "${SHARED_DIR}/.env" ]; then
        ln -sf "${SHARED_DIR}/.env" "${RELEASE_DIR}/.env"
    fi

    for dir in storage/app storage/framework storage/logs; do
        shared_sub="${SHARED_DIR}/${dir}"
        release_sub="${RELEASE_DIR}/${dir}"
        if [ -d "${shared_sub}" ]; then
            rm -rf "${release_sub}"
            ln -sf "${shared_sub}" "${release_sub}"
        fi
    done
fi

# --- Create storage symlink ---
echo "Creating storage symlink..."
php "${RELEASE_DIR}/artisan" storage:link --force 2>/dev/null || true

# --- Permissions check ---
for dir in "${RELEASE_DIR}/storage" "${RELEASE_DIR}/bootstrap/cache"; do
    if [ -d "$dir" ] && [ ! -w "$dir" ]; then
        echo "WARNING: Directory not writable: ${dir}"
    fi
done

# --- Run migrations ---
echo "Running migrations..."
php "${RELEASE_DIR}/artisan" migrate --force

# --- Cache ---
echo "Building caches..."
php "${RELEASE_DIR}/artisan" optimize:clear
php "${RELEASE_DIR}/artisan" config:cache
php "${RELEASE_DIR}/artisan" route:cache
php "${RELEASE_DIR}/artisan" view:cache

# --- Atomic switch ---
echo "Switching current release..."
ln -sfn "${RELEASE_DIR}" "${CURRENT_LINK}.new"
mv -T "${CURRENT_LINK}.new" "${CURRENT_LINK}"

# --- Restart queue workers ---
echo "Restarting queue workers..."
php "${CURRENT_LINK}/artisan" queue:restart 2>/dev/null || true

# --- Health checks ---
if [ -n "$HEALTH_URL" ]; then
    echo "Performing health checks..."

    for i in $(seq 1 10); do
        if curl -sf "${HEALTH_URL}/up" > /dev/null 2>&1; then
            echo "  /up OK"
            break
        fi
        sleep 2
    done

    for i in $(seq 1 10); do
        status=$(curl -s -o /dev/null -w "%{http_code}" "${HEALTH_URL}/ready" 2>&1 || echo "000")
        if [ "$status" = "200" ]; then
            echo "  /ready OK"
            break
        fi
        sleep 3
    done
fi

# --- Cleanup old releases ---
KEEP="${DEPLOY_KEEP_RELEASES:-5}"
echo "Cleaning up old releases (keeping ${KEEP})..."
ls -1t "${RELEASES_DIR}/" | tail -n +$((KEEP + 1)) | while read -r old; do
    old_path="${RELEASES_DIR}/${old}"
    if [ "$(readlink -f "${CURRENT_LINK}")" != "$(readlink -f "${old_path}")" ]; then
        echo "  Removing: ${old}"
        rm -rf "${old_path}"
    fi
done

echo "Deployment completed: ${RELEASE_ID}"
