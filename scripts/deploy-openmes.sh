#!/usr/bin/env bash
#
# deploy-openmes.sh — fail-closed deploy of a digest-pinned OpenMES image.
#
# Refuses to run unless OPENMES_IMAGE names an immutable digest
# (repo@sha256:<64 hex>). Validates the image and the compose file, updates all
# OpenMES services (not just `backend`), waits for /api/health, and verifies
# that every container runs the same image ID. Nothing here contains secrets:
# the compose file and the host .env keep those.
#
# Usage:
#   OPENMES_IMAGE=ghcr.io/xmichal88x/openmes-pa@sha256:<digest> \
#     scripts/deploy-openmes.sh
#
# Optional:
#   OPENMES_PULL_POLICY=never|missing|always|build   (default: never)
#   OPENMES_HEALTH_URL=mes.edeseczka.pl              (host or full URL)
#   OPENMES_HEALTH_TRIES=60                          (5s between attempts)
#   OPENMES_MANIFEST_TIMEOUT=60                      (seconds for a registry check)

set -Eeuo pipefail

log() { printf '[deploy-openmes] %s\n' "$*" >&2; }

die() {
    printf '[deploy-openmes] ERROR: %s\n' "$*" >&2
    exit 1
}

OPENMES_IMAGE="${OPENMES_IMAGE:-}"
[[ -n "$OPENMES_IMAGE" ]] \
    || die "OPENMES_IMAGE is required (repo@sha256:<64 hex>)"
[[ "$OPENMES_IMAGE" =~ ^.+@sha256:[0-9a-f]{64}$ ]] \
    || die "OPENMES_IMAGE must be immutable: got '$OPENMES_IMAGE', expected repo@sha256:<64 hex>"

command -v docker >/dev/null 2>&1 || die "docker not found"
docker compose version >/dev/null 2>&1 || die "docker compose plugin not found"
command -v curl >/dev/null 2>&1 || die "curl not found (needed for health check)"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

export OPENMES_IMAGE
export OPENMES_PULL_POLICY="${OPENMES_PULL_POLICY:-never}"

SERVICES=(backend reverb mqtt-listener modbus-poller queue-worker)
PROFILES=(--profile connectivity --profile workers)

UP_DONE=0
PREV_IMAGE_IDS=()
PREV_SERVICES=()

record_previous_images() {
    local svc cid image
    for svc in "${SERVICES[@]}"; do
        cid="$(docker compose ps -q "$svc" 2>/dev/null || true)"
        [[ -n "$cid" ]] || continue
        image="$(docker inspect -f '{{.Image}}' "$cid" 2>/dev/null || true)"
        [[ -n "$image" ]] || continue
        PREV_IMAGE_IDS+=("$image")
        PREV_SERVICES+=("$svc")
    done
}

rollback_previous() {
    [[ "$UP_DONE" -eq 1 ]] || return 0
    [[ "${#PREV_IMAGE_IDS[@]}" -eq "${#SERVICES[@]}" ]] || {
        log "partial deploy: no complete previous image set to roll back to"
        return 1
    }

    local first="${PREV_IMAGE_IDS[0]}" id
    for id in "${PREV_IMAGE_IDS[@]}"; do
        [[ "$id" == "$first" ]] || {
            log "partial deploy: previous containers used different images; manual rollback required"
            return 1
        }
    done

    log "rolling back all OpenMES services to previous image ID $first"
    if OPENMES_IMAGE="$first" docker compose "${PROFILES[@]}" up -d "${SERVICES[@]}"; then
        log "rollback completed"
    else
        log "rollback FAILED — services may be partially updated"
    fi
    return 0
}

fail_after_up() {
    printf '[deploy-openmes] ERROR: %s\n' "$*" >&2
    rollback_previous || true
    exit 1
}

record_previous_images

# 1. Image must exist (locally) or resolve in the registry before anything is
#    recreated. A missing/unauthorised digest stops the deploy here.
if docker image inspect "$OPENMES_IMAGE" >/dev/null 2>&1; then
    log "image present locally: $OPENMES_IMAGE"
else
    log "image not local; verifying manifest: $OPENMES_IMAGE"
    timeout "${OPENMES_MANIFEST_TIMEOUT:-60}" docker manifest inspect "$OPENMES_IMAGE" >/dev/null 2>&1 \
        || die "docker manifest inspect failed/timed out for $OPENMES_IMAGE (missing, private, or bad digest)"
fi

# 2. Compose must render; a missing OPENMES_IMAGE or malformed file fails here.
log "validating compose configuration"
docker compose config -q || die "docker compose config failed"

# 3. Fetch the exact digest. `never` means "use the local copy", so an explicit
#    pull happens only when the image is absent; otherwise the pull is explicit.
if [[ "$OPENMES_PULL_POLICY" != "never" ]]; then
    log "pulling all OpenMES services (OPENMES_PULL_POLICY=$OPENMES_PULL_POLICY)"
    docker compose "${PROFILES[@]}" pull "${SERVICES[@]}" || die "docker compose pull failed"
elif ! docker image inspect "$OPENMES_IMAGE" >/dev/null 2>&1; then
    log "pulling pinned image: $OPENMES_IMAGE"
    docker pull "$OPENMES_IMAGE" || die "docker pull failed"
else
    log "OPENMES_PULL_POLICY=never; using the local image"
fi

# 4. Recreate every OpenMES service, not only backend. Depends-on (postgres)
#    and the shared image are handled by compose.
log "starting all OpenMES services: ${SERVICES[*]}"
UP_DONE=1
docker compose "${PROFILES[@]}" up -d "${SERVICES[@]}" \
    || fail_after_up "docker compose up failed"

# 5. Wait for the application health endpoint.
HEALTH_TARGET="${OPENMES_HEALTH_URL:-mes.edeseczka.pl}"
case "$HEALTH_TARGET" in
    http://*|https://*) ;;
    *) HEALTH_TARGET="https://${HEALTH_TARGET}" ;;
esac
HEALTH_URL="${HEALTH_TARGET%/}/api/health"
HEALTH_TRIES="${OPENMES_HEALTH_TRIES:-60}"

log "waiting for ${HEALTH_URL} (up to $((HEALTH_TRIES * 5))s)"
healthy=0
for _ in $(seq 1 "$HEALTH_TRIES"); do
    if curl -fsS -o /dev/null --max-time 10 "$HEALTH_URL"; then
        healthy=1
        break
    fi
    sleep 5
done
[[ "$healthy" -eq 1 ]] || fail_after_up "health check did not return 200: $HEALTH_URL"

# 6. Every OpenMES container must run the same image ID, and that ID must be
#    the pinned one — a silent rebuild/stale tag fails the deploy.
log "verifying container image IDs"
IMAGE_IDS=()
for svc in "${SERVICES[@]}"; do
    cid="$(docker compose ps -q "$svc" 2>/dev/null || true)"
    [[ -n "$cid" ]] || fail_after_up "no container for service '$svc'"
    IMAGE_IDS+=("$(docker inspect -f '{{.Image}}' "$cid")")
done

UNIQUE_COUNT="$(printf '%s\n' "${IMAGE_IDS[@]}" | sort -u | wc -l | tr -d ' ')"
[[ "$UNIQUE_COUNT" == "1" ]] \
    || fail_after_up "containers use ${UNIQUE_COUNT} different image IDs: ${IMAGE_IDS[*]}"

RUNNING_ID="${IMAGE_IDS[0]}"
PINNED_ID="$(docker image inspect -f '{{.Id}}' "$OPENMES_IMAGE")"
[[ "$RUNNING_ID" == "$PINNED_ID" ]] \
    || fail_after_up "containers run $RUNNING_ID but pinned image is $PINNED_ID"

# 7. Report. RepoDigests is empty for a purely local image, hence the fallback.
log "OK — deploy complete"
printf 'image:    %s\n' "$OPENMES_IMAGE"
printf 'image ID: %s\n' "$RUNNING_ID"
docker image inspect -f 'digest:   {{join .RepoDigests ", "}}' "$OPENMES_IMAGE" 2>/dev/null \
    | sed 's/^digest:   $/digest:   (local image, no repo digest)/' >&2 || true

exit 0
