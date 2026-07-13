#!/usr/bin/env bash

set -Eeuo pipefail

APP_ROOT=""
HEALTH_URL=""
DRY_RUN=false
FORCE=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-root)    APP_ROOT="$2"; shift 2 ;;
        --health-url)  HEALTH_URL="$2"; shift 2 ;;
        --dry-run)     DRY_RUN=true; shift ;;
        --force)       FORCE=true; shift ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [ -z "$APP_ROOT" ]; then
    echo "Usage: $0 --app-root=<path> [--health-url=<url>] [--dry-run] [--force]"
    exit 2
fi

RELEASES_DIR="${APP_ROOT}/releases"
CURRENT_LINK="${APP_ROOT}/current"

if [ "$DRY_RUN" = true ]; then
    echo "--- Dry Run ---"
    echo "Current release: $(readlink -f "${CURRENT_LINK}" 2>/dev/null || echo 'none')"
    echo "Previous releases:"
    ls -1t "${RELEASES_DIR}/" 2>/dev/null | head -5 || echo "  none"
    echo "Health URL: ${HEALTH_URL:-not set}"
    echo "---"
    exit 0
fi

# --- Production protection ---
if [ "$FORCE" != true ]; then
    echo "ERROR: Production rollback requires --force."
    exit 2
fi

# --- Acquire lock ---
LOCK_FILE="${APP_ROOT}/deploy.lock"
exec 200>"${LOCK_FILE}"
if ! flock -n 200; then
    echo "ERROR: Another deployment is in progress."
    exit 3
fi
trap 'flock -u 200; rm -f "${LOCK_FILE}"' EXIT

# --- Get current and previous releases ---
if [ ! -L "$CURRENT_LINK" ]; then
    echo "ERROR: No current release link found."
    exit 2
fi

CURRENT_RELEASE=$(readlink -f "${CURRENT_LINK}")
echo "Current release: ${CURRENT_RELEASE}"

PREVIOUS_RELEASE=""
for dir in $(ls -1t "${RELEASES_DIR}/"); do
    dir_path="${RELEASES_DIR}/${dir}"
    if [ "$(readlink -f "${dir_path}")" != "$CURRENT_RELEASE" ]; then
        PREVIOUS_RELEASE="$dir_path"
        break
    fi
done

if [ -z "$PREVIOUS_RELEASE" ]; then
    echo "ERROR: No previous release found."
    exit 2
fi

echo "Previous release: ${PREVIOUS_RELEASE}"

# --- Verify previous release metadata ---
if [ ! -f "${PREVIOUS_RELEASE}/RELEASE.json" ]; then
    echo "WARNING: Previous release does not contain RELEASE.json."
fi

# --- Switch symlink ---
echo "Switching to previous release..."
ln -sfn "${PREVIOUS_RELEASE}" "${CURRENT_LINK}.new"
mv -T "${CURRENT_LINK}.new" "${CURRENT_LINK}"

# --- Rebuild caches for the previous release ---
echo "Rebuilding caches..."
php "${CURRENT_LINK}/artisan" optimize:clear || { echo "ERROR: optimize:clear failed"; exit 5; }
php "${CURRENT_LINK}/artisan" config:cache || { echo "ERROR: config:cache failed"; exit 5; }
php "${CURRENT_LINK}/artisan" route:cache || { echo "ERROR: route:cache failed"; exit 5; }
php "${CURRENT_LINK}/artisan" view:cache || { echo "ERROR: view:cache failed"; exit 5; }

# --- Restart queue workers ---
echo "Restarting queue workers..."
php "${CURRENT_LINK}/artisan" queue:restart 2>/dev/null || true

# --- Health checks ---
if [ -n "$HEALTH_URL" ]; then
    echo "Verifying health..."

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

echo "Rollback completed."
echo "NOTE: Database was not rolled back. Verify schema compatibility."
