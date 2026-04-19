#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
source "${SCRIPT_DIR}/deploy.config.sh"

TARGET_COLOR="${1:-}"
TARGET_PROJECT="${2:-}"
TARGET_ENV_FILE="${3:-}"

log() { echo "[traefik-switch] $(date '+%H:%M:%S') $*"; }
err() { echo "[traefik-switch] ERROR: $*" >&2; exit 1; }

[[ -n "${TARGET_COLOR}" ]] || err "Target color is required"
[[ -n "${TARGET_PROJECT}" ]] || err "Target compose project is required"
[[ -n "${TARGET_ENV_FILE}" ]] || err "Target env file is required"
[[ -f "${TARGET_ENV_FILE}" ]] || err "Target env file not found: ${TARGET_ENV_FILE}"

TRAEFIK_DYNAMIC_DIR="${TRAEFIK_DYNAMIC_DIR:-$(dirname "${DEPLOY_RUNTIME_ROOT}")/current/config/traefik/dynamic}"
TRAEFIK_DYNAMIC_FILE="${TRAEFIK_DYNAMIC_FILE:-${TRAEFIK_DYNAMIC_DIR}/dynamic-${COMPOSE_PROJECT_BASE}.yml}"
TRAEFIK_DYNAMIC_BACKUP="${TRAEFIK_DYNAMIC_FILE}.previous"
TMP_FILE="${TRAEFIK_DYNAMIC_DIR}/.dynamic-${COMPOSE_PROJECT_BASE}.yml.tmp.$$"
HTTP_SERVICE="${COMPOSE_PROJECT_BASE}-${TARGET_COLOR}-http"
REVERB_SERVICE="${COMPOSE_PROJECT_BASE}-${TARGET_COLOR}-reverb"
HTTP_ALIAS="${TARGET_PROJECT}-nginx"
REVERB_ALIAS="${TARGET_PROJECT}-reverb"
TENANT_RULE=""
TENANT_TLS_DOMAIN_BLOCK=""
TENANT_HTTP_ROUTER=""
TENANT_REVERB_ROUTER=""
REVERB_ROUTER=""

mkdir -p "${TRAEFIK_DYNAMIC_DIR}"

if [[ -n "${TENANT_WILDCARD_DOMAIN:-}" && "${TENANT_WILDCARD_DOMAIN}" != "null" ]]; then
    base_domain="${TENANT_WILDCARD_DOMAIN#\*.}"
    escaped_base="$(printf '%s' "${base_domain}" | sed 's/\./\\./g')"
    TENANT_RULE="HostRegexp(\`^[a-z0-9-]+\\.${escaped_base}$\`)"
    TENANT_TLS_DOMAIN_BLOCK="        domains:
          - main: '${APP_DOMAIN}'
            sans:
              - '${TENANT_WILDCARD_DOMAIN}'"
    TENANT_HTTP_ROUTER="    ${COMPOSE_PROJECT_BASE}-tenant-http:
      entryPoints:
        - websecure
      rule: '${TENANT_RULE}'
      priority: 2000
      service: ${HTTP_SERVICE}
      middlewares:
        - secure-headers@file
      tls:
        certResolver: ${TRAEFIK_CERT_RESOLVER:-letsencrypt}
${TENANT_TLS_DOMAIN_BLOCK}"
fi

if [[ "${REVERB_ENABLED:-false}" == "true" ]]; then
    REVERB_ROUTER="    ${COMPOSE_PROJECT_BASE}-reverb:
      entryPoints:
        - websecure
      rule: 'Host(\`${APP_DOMAIN}\`) && PathPrefix(\`${REVERB_PATH:-/app}\`)'
      priority: 2100
      service: ${REVERB_SERVICE}
      tls:
        certResolver: ${TRAEFIK_CERT_RESOLVER:-letsencrypt}"
    if [[ -n "${TENANT_RULE}" ]]; then
        TENANT_REVERB_ROUTER="    ${COMPOSE_PROJECT_BASE}-tenant-reverb:
      entryPoints:
        - websecure
      rule: '${TENANT_RULE} && PathPrefix(\`${REVERB_PATH:-/app}\`)'
      priority: 2100
      service: ${REVERB_SERVICE}
      tls:
        certResolver: ${TRAEFIK_CERT_RESOLVER:-letsencrypt}
${TENANT_TLS_DOMAIN_BLOCK}"
    fi
fi

cat > "${TMP_FILE}" <<EOF
http:
  routers:
    ${COMPOSE_PROJECT_BASE}-http:
      entryPoints:
        - websecure
      rule: 'Host(\`${APP_DOMAIN}\`)'
      priority: 2000
      service: ${HTTP_SERVICE}
      middlewares:
        - secure-headers@file
      tls:
        certResolver: ${TRAEFIK_CERT_RESOLVER:-letsencrypt}
${TENANT_HTTP_ROUTER}
${REVERB_ROUTER}
${TENANT_REVERB_ROUTER}
  services:
    ${HTTP_SERVICE}:
      loadBalancer:
        servers:
          - url: "http://${HTTP_ALIAS}:8080"
        healthCheck:
          path: /readyz
          interval: 30s
    ${REVERB_SERVICE}:
      loadBalancer:
        servers:
          - url: "http://${REVERB_ALIAS}:${REVERB_PORT:-6001}"
EOF

if command -v yq >/dev/null 2>&1; then
    yq e '.http.routers' "${TMP_FILE}" >/dev/null || err "Generated Traefik dynamic config is invalid YAML"
else
    grep -q '^http:' "${TMP_FILE}" || err "Generated Traefik dynamic config did not contain http root"
fi

if docker ps --format '{{.Names}}' | grep -q 'traefik'; then
    traefik_container="$(docker ps --format '{{.Names}}' | grep 'traefik' | head -1)"
    docker exec "${traefik_container}" traefik healthcheck --ping >/dev/null \
        || err "Traefik healthcheck failed before route switch"
fi

if [[ -f "${TRAEFIK_DYNAMIC_FILE}" ]]; then
    cp "${TRAEFIK_DYNAMIC_FILE}" "${TRAEFIK_DYNAMIC_BACKUP}"
fi

mv "${TMP_FILE}" "${TRAEFIK_DYNAMIC_FILE}"
log "Switched ${TRAEFIK_DYNAMIC_FILE} to ${TARGET_COLOR} (${TARGET_PROJECT})"
