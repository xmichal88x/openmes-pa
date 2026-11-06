#!/usr/bin/env bash
#
# deploy-openmes.sh — fail-closed deploy of a digest-pinned OpenMES image.
#
# Refuses to run unless OPENMES_IMAGE names an immutable digest
# (repo@sha256:<64 hex>). Validates the image and the compose file, recreates
# the selected OpenMES services, starts backend first and waits for /api/health
# before the sidecars, then verifies that every container runs the same image
# ID. Nothing here contains secrets: the compose file and the host .env keep
# those.
#
# Compose directory — first candidate containing docker-compose.yml wins:
#   1. OPENMES_COMPOSE_DIR (when set; must contain docker-compose.yml)
#   2. <directory of this script>/..
#   3. <directory of this script>
#   4. current working directory
# If none contains docker-compose.yml the script fails. This lets the same file
# work from scripts/ in the repo and from /opt/openmes/scripts/.
#
# Services:
#   backend, reverb    always deployed.
#   queue-worker       added when OPENMES_ENABLE_QUEUE=1 OR its container exists.
#   mqtt-listener      added when OPENMES_ENABLE_MQTT=1 OR its container exists.
#   modbus-poller      ONLY when OPENMES_ENABLE_MODBUS=1 (default: off).
# Existing sidecars are updated, but new ones are never enabled by accident.
# modbus-poller is opt-in because without a configured Modbus connection the
# poller exits immediately and crash-loops. The ENABLE_* values may be set in
# the environment or in the compose directory's .env file.
#
# Usage:
#   OPENMES_IMAGE=ghcr.io/xmichal88x/openmes-pa@sha256:<digest> \
#     scripts/deploy-openmes.sh
#
# Optional:
#   OPENMES_COMPOSE_DIR=/opt/openmes                 (explicit compose directory)
#   OPENMES_PULL_POLICY=never|missing|always|build   (default: never)
#   OPENMES_HEALTH_URL=mes.edeseczka.pl              (host or full URL)
#   OPENMES_HEALTH_TRIES=60                          (5s between attempts)
#   OPENMES_MANIFEST_TIMEOUT=60                      (seconds for a registry check)
#   OPENMES_ENABLE_QUEUE=0|1                         (default: 0)
#   OPENMES_ENABLE_MQTT=0|1                          (default: 0)
#   OPENMES_ENABLE_MODBUS=0|1                        (default: 0)
#   OPENMES_SOURCE_URL_OVERRIDE=...                  (optional AGPL link override)
#
# AGPL §13 provenance: after the image is available locally the script reads
# org.opencontainers.image.revision and always exports APP_SOURCE_URL to
# https://github.com/xmichal88x/openmes-pa/tree/<revision>. A missing/empty
# label aborts the deploy. APP_SOURCE_URL is a result, never an input: use
# OPENMES_SOURCE_URL_OVERRIDE only if the image label itself is wrong.

set -Eeuo pipefail

log() { printf '[deploy-openmes] %s\n' "$*" >&2; }

die() {
    printf '[deploy-openmes] ERROR: %s\n' "$*" >&2
    exit 1
}

usage() {
    printf '%s\n' \
        'Usage: OPENMES_IMAGE=repo@sha256:<64 hex> deploy-openmes.sh' \
        '' \
        'Optional env:' \
        '  OPENMES_COMPOSE_DIR   directory containing docker-compose.yml' \
        '  OPENMES_PULL_POLICY   never|missing|always|build (default: never)' \
        '  OPENMES_HEALTH_URL    host or full URL (default: mes.edeseczka.pl)' \
        '  OPENMES_HEALTH_TRIES  attempts, 5s apart (default: 60)' \
        '  OPENMES_ENABLE_QUEUE  0|1 (default: 0, or keep an existing container)' \
        '  OPENMES_ENABLE_MQTT   0|1 (default: 0, or keep an existing container)' \
        '  OPENMES_ENABLE_MODBUS 0|1 (default: 0, opt-in only)' \
        '  OPENMES_SOURCE_URL_OVERRIDE  AGPL source link override (optional)' \
        '' \
        'Always deployed: backend reverb. See the header for details.'
}

case "${1:-}" in
    -h|--help) usage; exit 0 ;;
    "") ;;
    *) die "unknown argument: $1 (try --help)" ;;
esac

OPENMES_IMAGE="${OPENMES_IMAGE:-}"
[[ -n "$OPENMES_IMAGE" ]] \
    || die "OPENMES_IMAGE is required (repo@sha256:<64 hex>)"
[[ "$OPENMES_IMAGE" =~ ^.+@sha256:[0-9a-f]{64}$ ]] \
    || die "OPENMES_IMAGE must be immutable: got '$OPENMES_IMAGE', expected repo@sha256:<64 hex>"

command -v docker >/dev/null 2>&1 || die "docker not found"
docker compose version >/dev/null 2>&1 || die "docker compose plugin not found"
command -v curl >/dev/null 2>&1 || die "curl not found (needed for health check)"

resolve_compose_dir() {
    local script_dir candidate
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

    if [[ -n "${OPENMES_COMPOSE_DIR:-}" ]]; then
        [[ -f "$OPENMES_COMPOSE_DIR/docker-compose.yml" ]] \
            || die "OPENMES_COMPOSE_DIR='$OPENMES_COMPOSE_DIR' has no docker-compose.yml"
        (cd "$OPENMES_COMPOSE_DIR" && pwd)
        return 0
    fi

    for candidate in "$script_dir/.." "$script_dir" "$PWD"; do
        if [[ -f "$candidate/docker-compose.yml" ]]; then
            (cd "$candidate" && pwd)
            return 0
        fi
    done

    die "docker-compose.yml not found in '$script_dir/..', '$script_dir' or '$PWD' (set OPENMES_COMPOSE_DIR)"
}

REPO_ROOT="$(resolve_compose_dir)"
cd "$REPO_ROOT"

export OPENMES_IMAGE
export OPENMES_PULL_POLICY="${OPENMES_PULL_POLICY:-never}"

PROFILES=(--profile connectivity --profile workers)
compose() { docker compose "${PROFILES[@]}" "$@"; }

env_toggle() {
    local key="$1" value
    value="${!key:-}"
    if [[ -z "$value" && -f "$REPO_ROOT/.env" ]]; then
        value="$(sed -n "s/^[[:space:]]*${key}[[:space:]]*=//p" "$REPO_ROOT/.env" | tail -n 1)"
        value="${value%$'\r'}"
        value="${value%\"}"; value="${value#\"}"
        value="${value%\'}"; value="${value#\'}"
    fi
    case "$value" in
        ""|0) printf '0' ;;
        1) printf '1' ;;
        *) die "$key must be 0 or 1 (got '$value')" ;;
    esac
}

service_exists() {
    local svc="$1" ids
    ids="$(compose ps -a -q "$svc" 2>/dev/null || true)"
    [[ -n "$ids" ]]
}

ENABLE_QUEUE="$(env_toggle OPENMES_ENABLE_QUEUE)"
ENABLE_MQTT="$(env_toggle OPENMES_ENABLE_MQTT)"
ENABLE_MODBUS="$(env_toggle OPENMES_ENABLE_MODBUS)"

SERVICES=(backend reverb)
if [[ "$ENABLE_QUEUE" == "1" ]] || service_exists queue-worker; then
    SERVICES+=(queue-worker)
fi
if [[ "$ENABLE_MQTT" == "1" ]] || service_exists mqtt-listener; then
    SERVICES+=(mqtt-listener)
fi
if [[ "$ENABLE_MODBUS" == "1" ]]; then
    SERVICES+=(modbus-poller)
fi
REST_SERVICES=()
if [[ "${#SERVICES[@]}" -gt 1 ]]; then
    REST_SERVICES=("${SERVICES[@]:1}")
fi

if [[ "${OPENMES_PRINT_PLAN:-0}" == "1" ]]; then
    printf 'SERVICES=%s\n' "${SERVICES[*]}"
    printf 'REST_SERVICES=%s\n' "${REST_SERVICES[*]}"
    exit 0
fi

log "compose directory: $REPO_ROOT"
log "services to deploy: ${SERVICES[*]} (modbus=$ENABLE_MODBUS)"

HEALTH_TARGET="${OPENMES_HEALTH_URL:-mes.edeseczka.pl}"
case "$HEALTH_TARGET" in
    http://*|https://*) ;;
    *) HEALTH_TARGET="https://${HEALTH_TARGET}" ;;
esac
HEALTH_URL="${HEALTH_TARGET%/}/api/health"
HEALTH_TRIES="${OPENMES_HEALTH_TRIES:-60}"

wait_for_health() {
    local i
    for ((i = 1; i <= HEALTH_TRIES; i++)); do
        if curl -fsS -o /dev/null --max-time 10 "$HEALTH_URL"; then
            return 0
        fi
        sleep 5
    done
    return 1
}

UP_DONE=0
PREV_IMAGE_IDS=()
PREV_SERVICES=()

record_previous_images() {
    local svc cid image
    for svc in "${SERVICES[@]}"; do
        cid="$(compose ps -q "$svc" 2>/dev/null || true)"
        [[ -n "$cid" ]] || continue
        image="$(docker inspect -f '{{.Image}}' "$cid" 2>/dev/null || true)"
        [[ -n "$image" ]] || continue
        PREV_IMAGE_IDS+=("$image")
        PREV_SERVICES+=("$svc")
    done
}

rollback_previous() {
    [[ "$UP_DONE" -eq 1 ]] || return 0

    local -A have=()
    local svc missing=()
    for svc in "${PREV_SERVICES[@]}"; do
        have["$svc"]=1
    done
    for svc in "${SERVICES[@]}"; do
        [[ -n "${have[$svc]:-}" ]] || missing+=("$svc")
    done
    if [[ "${#missing[@]}" -ne 0 ]]; then
        log "rollback skipped: no previous image for ${missing[*]} — manual rollback required"
        return 1
    fi

    local first="${PREV_IMAGE_IDS[0]}" id
    for id in "${PREV_IMAGE_IDS[@]}"; do
        [[ "$id" == "$first" ]] || {
            log "rollback skipped: previous containers used different images; manual rollback required"
            return 1
        }
    done

    log "rolling back ${SERVICES[*]} to previous image ID $first"
    if OPENMES_IMAGE="$first" compose up -d backend; then
        if wait_for_health; then
            log "rollback: backend healthy"
        else
            log "rollback: backend health check did not pass (continuing)"
        fi
        if [[ "${#REST_SERVICES[@]}" -gt 0 ]]; then
            OPENMES_IMAGE="$first" compose up -d "${REST_SERVICES[@]}" \
                || log "rollback: sidecar restart failed — services may be partially updated"
        fi
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
compose config -q || die "docker compose config failed"

# 3. Fetch the exact digest. `never` means "use the local copy", so an explicit
#    pull happens only when the image is absent; otherwise the pull is explicit.
if [[ "$OPENMES_PULL_POLICY" != "never" ]]; then
    log "pulling selected services (OPENMES_PULL_POLICY=$OPENMES_PULL_POLICY)"
    compose pull "${SERVICES[@]}" || die "docker compose pull failed"
elif ! docker image inspect "$OPENMES_IMAGE" >/dev/null 2>&1; then
    log "pulling pinned image: $OPENMES_IMAGE"
    docker pull "$OPENMES_IMAGE" || die "docker pull failed"
else
    log "OPENMES_PULL_POLICY=never; using the local image"
fi

# 4. AGPL §13 provenance. The source link shown in the UI must point at the
#    exact commit that produced this image, never a mutable branch. A missing
#    revision label aborts the deploy (fail closed: no provenance, no deploy).
#    APP_SOURCE_URL is derived here on every deploy; OPENMES_SOURCE_URL_OVERRIDE
#    is the only override, so a stale .env value cannot pin an old revision.
REV="$(docker image inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$OPENMES_IMAGE" 2>/dev/null || true)"
if [[ -z "$REV" || "$REV" == "<no value>" ]]; then
    die "image $OPENMES_IMAGE has no org.opencontainers.image.revision label — refusing deploy without provenance"
fi
if [[ -n "${OPENMES_SOURCE_URL_OVERRIDE:-}" ]]; then
    export APP_SOURCE_URL="$OPENMES_SOURCE_URL_OVERRIDE"
else
    export APP_SOURCE_URL="https://github.com/xmichal88x/openmes-pa/tree/$REV"
fi
log "AGPL source URL: $APP_SOURCE_URL"

# 5. Backend first. It runs config:cache against the shared bootstrap cache
#    volume; starting sidecars in parallel with it caused a one-off restart.
log "starting backend"
UP_DONE=1
compose up -d backend || fail_after_up "docker compose up backend failed"

log "waiting for ${HEALTH_URL} (up to $((HEALTH_TRIES * 5))s)"
wait_for_health || fail_after_up "health check did not return 200: $HEALTH_URL"
log "backend is healthy"

# 6. Then the sidecars. Only selected services are named explicitly, so a
#    sidecar that is not wanted is never started by accident.
if [[ "${#REST_SERVICES[@]}" -gt 0 ]]; then
    log "starting sidecars: ${REST_SERVICES[*]}"
    compose up -d "${REST_SERVICES[@]}" \
        || fail_after_up "docker compose up sidecars failed"
fi

# 7. Every selected container must run the same image ID, and that ID must be
#    the pinned one — a silent rebuild/stale tag fails the deploy.
log "verifying container image IDs"
IMAGE_IDS=()
for svc in "${SERVICES[@]}"; do
    cid="$(compose ps -q "$svc" 2>/dev/null || true)"
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

# 8. Report. RepoDigests is empty for a purely local image, hence the fallback.
log "OK — deploy complete"
printf 'image:    %s\n' "$OPENMES_IMAGE"
printf 'image ID: %s\n' "$RUNNING_ID"
docker image inspect -f 'digest:   {{join .RepoDigests ", "}}' "$OPENMES_IMAGE" 2>/dev/null \
    | sed 's/^digest:   $/digest:   (local image, no repo digest)/' >&2 || true

exit 0
