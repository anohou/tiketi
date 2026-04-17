#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  lib/rbac.sh — Pure-bash rbac.yml loader                                   ║
# ║                                                                             ║
# ║  Reads deployment/config/rbac.yml and exports shell variables + helper      ║
# ║  functions.                                                                 ║
# ║  Existing environment variables always take priority.                       ║
# ╚══════════════════════════════════════════════════════════════════════════════╝

_DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
_RBAC_FILE="${_DEPLOY_DIR}/config/rbac.yml"

[[ -f "${_RBAC_FILE}" ]] || {
    echo "[rbac] ERROR: rbac.yml not found at ${_RBAC_FILE}" >&2
    exit 1
}

_rbac_cfg() {
    local file="${_RBAC_FILE}"
    local l1="$1" l2="${2:-}"
    local in_l1=false value=""

    while IFS= read -r raw_line; do
        local line="${raw_line%%  #*}"
        line="${line%"${line##*[! ]}"}"

        [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue

        local stripped="${line#"${line%%[! ]*}"}"
        local indent=$(( ${#line} - ${#stripped} ))

        if [[ $indent -eq 0 ]]; then
            in_l1=false
            if [[ "$stripped" == "${l1}:"* ]]; then
                in_l1=true
                if [[ -z "$l2" ]]; then
                    value="${stripped#*:}"
                    value="${value#"${value%%[! ]*}"}"
                    value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
                    echo "$value"
                    return 0
                fi
            fi
        elif [[ $indent -eq 2 && "$in_l1" == true ]]; then
            if [[ "$stripped" == "${l2}:"* ]]; then
                value="${stripped#*:}"
                value="${value#"${value%%[! ]*}"}"
                value="${value%\"}"; value="${value#\"}"; value="${value%\'}"; value="${value#\'}"
                echo "$value"
                return 0
            fi
        fi
    done < "$file"

    echo ""
}

_rbac_norm_bool() {
    case "$1" in
        [Tt][Rr][Uu][Ee]|1|[Yy][Ee][Ss]|[Oo][Nn]) echo "true" ;;
        [Ff][Aa][Ll][Ss][Ee]|0|[Nn][Oo]|[Oo][Ff][Ff]|"") echo "false" ;;
        *) echo "$1" ;;
    esac
}

rbac_bool_to_sql() {
    case "$(_rbac_norm_bool "$1")" in
        true) echo "TRUE" ;;
        false) echo "FALSE" ;;
        *) echo "$1" ;;
    esac
}

derive_owner_role() {
    local runtime_user="$1"

    [[ -n "${RBAC_OWNER_RUNTIME_SUFFIX}" ]] || { echo "[rbac] ERROR: RBAC_OWNER_RUNTIME_SUFFIX is empty" >&2; return 1; }
    [[ -n "${RBAC_OWNER_ROLE_SUFFIX}" ]] || { echo "[rbac] ERROR: RBAC_OWNER_ROLE_SUFFIX is empty" >&2; return 1; }
    [[ "${runtime_user}" == *"${RBAC_OWNER_RUNTIME_SUFFIX}" ]] || {
        echo "[rbac] ERROR: runtime role '${runtime_user}' does not end with '${RBAC_OWNER_RUNTIME_SUFFIX}'" >&2
        return 1
    }

    printf '%s%s\n' "${runtime_user%"${RBAC_OWNER_RUNTIME_SUFFIX}"}" "${RBAC_OWNER_ROLE_SUFFIX}"
}

RBAC_SCHEMA_NAME="${RBAC_SCHEMA_NAME:-$(_rbac_cfg schema name)}"

RBAC_OWNER_RUNTIME_SUFFIX="${RBAC_OWNER_RUNTIME_SUFFIX:-$(_rbac_cfg owner_role runtime_suffix)}"
RBAC_OWNER_ROLE_SUFFIX="${RBAC_OWNER_ROLE_SUFFIX:-$(_rbac_cfg owner_role owner_suffix)}"

RBAC_RUNTIME_USERNAME_ENV="${RBAC_RUNTIME_USERNAME_ENV:-$(_rbac_cfg role_env runtime_username)}"
RBAC_RUNTIME_PASSWORD_ENV="${RBAC_RUNTIME_PASSWORD_ENV:-$(_rbac_cfg role_env runtime_password)}"
RBAC_MIGRATOR_USERNAME_ENV="${RBAC_MIGRATOR_USERNAME_ENV:-$(_rbac_cfg role_env migrator_username)}"
RBAC_MIGRATOR_PASSWORD_ENV="${RBAC_MIGRATOR_PASSWORD_ENV:-$(_rbac_cfg role_env migrator_password)}"
RBAC_DBA_USERNAME_ENV="${RBAC_DBA_USERNAME_ENV:-$(_rbac_cfg role_env dba_username)}"
RBAC_DBA_PASSWORD_ENV="${RBAC_DBA_PASSWORD_ENV:-$(_rbac_cfg role_env dba_password)}"
RBAC_BACKUP_USERNAME_ENV="${RBAC_BACKUP_USERNAME_ENV:-$(_rbac_cfg role_env backup_username)}"
RBAC_BACKUP_PASSWORD_ENV="${RBAC_BACKUP_PASSWORD_ENV:-$(_rbac_cfg role_env backup_password)}"

RBAC_RUNTIME_CONNECTION_LIMIT="${RBAC_RUNTIME_CONNECTION_LIMIT:-$(_rbac_cfg connection_limits runtime)}"
RBAC_MIGRATOR_CONNECTION_LIMIT="${RBAC_MIGRATOR_CONNECTION_LIMIT:-$(_rbac_cfg connection_limits migrator)}"
RBAC_DBA_CONNECTION_LIMIT="${RBAC_DBA_CONNECTION_LIMIT:-$(_rbac_cfg connection_limits dba)}"
RBAC_BACKUP_CONNECTION_LIMIT="${RBAC_BACKUP_CONNECTION_LIMIT:-$(_rbac_cfg connection_limits backup)}"

RBAC_MIGRATOR_LOGIN_NOINHERIT="$(_rbac_norm_bool "${RBAC_MIGRATOR_LOGIN_NOINHERIT:-$(_rbac_cfg migrator_policy login_noinherit)}")"
RBAC_OWNER_MEMBERSHIP_INHERIT="$(_rbac_norm_bool "${RBAC_OWNER_MEMBERSHIP_INHERIT:-$(_rbac_cfg migrator_policy owner_membership_inherit)}")"
RBAC_OWNER_MEMBERSHIP_SET="$(_rbac_norm_bool "${RBAC_OWNER_MEMBERSHIP_SET:-$(_rbac_cfg migrator_policy owner_membership_set)}")"

RBAC_RUNTIME_TABLE_PRIVILEGES="${RBAC_RUNTIME_TABLE_PRIVILEGES:-$(_rbac_cfg privileges runtime_tables)}"
RBAC_RUNTIME_SEQUENCE_PRIVILEGES="${RBAC_RUNTIME_SEQUENCE_PRIVILEGES:-$(_rbac_cfg privileges runtime_sequences)}"
RBAC_BACKUP_TABLE_PRIVILEGES="${RBAC_BACKUP_TABLE_PRIVILEGES:-$(_rbac_cfg privileges backup_tables)}"
RBAC_BACKUP_SEQUENCE_PRIVILEGES="${RBAC_BACKUP_SEQUENCE_PRIVILEGES:-$(_rbac_cfg privileges backup_sequences)}"
RBAC_DBA_SCHEMA_PRIVILEGES="${RBAC_DBA_SCHEMA_PRIVILEGES:-$(_rbac_cfg privileges dba_schema)}"
RBAC_DBA_TABLE_PRIVILEGES="${RBAC_DBA_TABLE_PRIVILEGES:-$(_rbac_cfg privileges dba_tables)}"
RBAC_DBA_SEQUENCE_PRIVILEGES="${RBAC_DBA_SEQUENCE_PRIVILEGES:-$(_rbac_cfg privileges dba_sequences)}"

export RBAC_SCHEMA_NAME \
       RBAC_OWNER_RUNTIME_SUFFIX RBAC_OWNER_ROLE_SUFFIX \
       RBAC_RUNTIME_USERNAME_ENV RBAC_RUNTIME_PASSWORD_ENV \
       RBAC_MIGRATOR_USERNAME_ENV RBAC_MIGRATOR_PASSWORD_ENV \
       RBAC_DBA_USERNAME_ENV RBAC_DBA_PASSWORD_ENV \
       RBAC_BACKUP_USERNAME_ENV RBAC_BACKUP_PASSWORD_ENV \
       RBAC_RUNTIME_CONNECTION_LIMIT RBAC_MIGRATOR_CONNECTION_LIMIT \
       RBAC_DBA_CONNECTION_LIMIT RBAC_BACKUP_CONNECTION_LIMIT \
       RBAC_MIGRATOR_LOGIN_NOINHERIT \
       RBAC_OWNER_MEMBERSHIP_INHERIT RBAC_OWNER_MEMBERSHIP_SET \
       RBAC_RUNTIME_TABLE_PRIVILEGES RBAC_RUNTIME_SEQUENCE_PRIVILEGES \
       RBAC_BACKUP_TABLE_PRIVILEGES RBAC_BACKUP_SEQUENCE_PRIVILEGES \
       RBAC_DBA_SCHEMA_PRIVILEGES RBAC_DBA_TABLE_PRIVILEGES RBAC_DBA_SEQUENCE_PRIVILEGES

unset _DEPLOY_DIR _RBAC_FILE
unset -f _rbac_cfg
