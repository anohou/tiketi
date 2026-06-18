#!/usr/bin/env bash

build_fingerprint_checksum_stdin() {
  if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 | awk '{print $1}'
  elif command -v sha256sum >/dev/null 2>&1; then
    sha256sum | awk '{print $1}'
  else
    openssl dgst -sha256 | awk '{print $NF}'
  fi
}

build_fingerprint_checksum_file() {
  local path="$1"
  cat "${path}" | build_fingerprint_checksum_stdin
}

build_fingerprint_env_value_from_file() {
  local file_path="$1"
  local key="$2"

  [[ -f "${file_path}" ]] || return 0
  grep -E "^${key}=" "${file_path}" | tail -1 | cut -d= -f2- || true
}

build_fingerprint_build_arg_keys() {
  cat <<'EOF'
VITE_APP_NAME
VITE_APP_ENV
PUBLIC_REVERB_APP_KEY
VITE_REVERB_HOST
VITE_REVERB_PORT
VITE_REVERB_SCHEME
EOF
}

build_fingerprint_resolve_build_arg_value() {
  local key="$1"
  local value="${!key:-}"

  if [[ -z "${value}" ]]; then
    value="$(build_fingerprint_env_value_from_file "${COMMON_ENV_PATH:-}" "${key}")"
  fi
  if [[ -z "${value}" ]]; then
    value="$(build_fingerprint_env_value_from_file "${PROJECT_ENV_PATH:-}" "${key}")"
  fi

  printf '%s' "${value}"
}

build_fingerprint_ignore_path() {
  local relative_path="$1"

  case "${relative_path}" in
    .git|.git/*|.github|.github/*|.idea|.idea/*|.vscode|.vscode/*|.claude|.claude/*)
      return 0
      ;;
    vendor|vendor/*|node_modules|node_modules/*)
      return 0
      ;;
    storage/framework/cache|storage/framework/cache/*|storage/framework/sessions|storage/framework/sessions/*|storage/framework/views|storage/framework/views/*)
      return 0
      ;;
    storage/logs|storage/logs/*|storage/debugbar|storage/debugbar/*)
      return 0
      ;;
    storage/app/private|storage/app/private/*|storage/app/public|storage/app/public/*|storage/app/purifier|storage/app/purifier/*)
      return 0
      ;;
    bootstrap/cache|bootstrap/cache/*|bootstrap/ssr|bootstrap/ssr/*)
      return 0
      ;;
    public/build|public/build/*|public/storage|public/storage/*|public/hot)
      return 0
      ;;
    deployment/.deploy|deployment/.deploy/*|deployment/.deploy-lock|deployment/.deploy-lock/*|deployment/.deploy-state|deployment/.deploy-state/*)
      return 0
      ;;
    deployment/runtime-public|deployment/runtime-public/*|deployment/runtime-public-blue|deployment/runtime-public-blue/*|deployment/runtime-public-green|deployment/runtime-public-green/*|deployment/runtime-public-*|deployment/runtime-public-*/*)
      return 0
      ;;
    deployment/persistent-public|deployment/persistent-public/*|deployment.working|deployment.working/*)
      return 0
      ;;
    coverage|coverage/*|dist|dist/*|build|build/*)
      return 0
      ;;
    .DS_Store|*.log|*.log.*)
      return 0
      ;;
    deployment/.env|deployment/.env.*|deployment/.last-*|deployment/.release-manifest.env)
      return 0
      ;;
    deployment/.last-known-good.env|deployment/.last-known-good-compose.yml|deployment/.build-timings.log)
      return 0
      ;;
    deployment/.hardened-deploy-toolkit.manifest.yml)
      return 0
      ;;
  esac

  return 1
}

build_fingerprint_compute_source_tree_fingerprint() {
  local source_dir="$1"
  local absolute_path relative_path file_hash

  find "${source_dir}" -mindepth 1 \( -type f -o -type l \) | LC_ALL=C sort | while IFS= read -r absolute_path; do
    relative_path="${absolute_path#${source_dir}/}"
    if build_fingerprint_ignore_path "${relative_path}"; then
      continue
    fi

    if [[ -L "${absolute_path}" ]]; then
      printf 'symlink:%s:%s\n' "${relative_path}" "$(readlink "${absolute_path}")"
      continue
    fi

    file_hash="$(build_fingerprint_checksum_file "${absolute_path}")"
    printf 'file:%s:%s\n' "${relative_path}" "${file_hash}"
  done | build_fingerprint_checksum_stdin
}

build_fingerprint_compute_build_arg_fingerprint() {
  local key value

  {
    while IFS= read -r key; do
      [[ -n "${key}" ]] || continue
      value="$(build_fingerprint_resolve_build_arg_value "${key}")"
      printf 'build_arg:%s=%s\n' "${key}" "${value}"
    done < <(build_fingerprint_build_arg_keys)

    printf 'build_setting:ASSET_BUILD_ENABLED=%s\n' "${ASSET_BUILD_ENABLED:-}"
    printf 'build_setting:BUILD_MODE=%s\n' "${BUILD_MODE:-}"
    printf 'build_setting:BUILD_RUNTIME_MODE=%s\n' "${BUILD_RUNTIME_MODE:-}"
    printf 'build_setting:BUILD_RUNTIME_IMAGE=%s\n' "${BUILD_RUNTIME_IMAGE:-}"
  } | build_fingerprint_checksum_stdin
}

build_fingerprint_compute_build_input_fingerprint() {
  local source_dir="$1"
  local source_tree_fingerprint=""
  local build_arg_fingerprint=""

  source_tree_fingerprint="$(build_fingerprint_compute_source_tree_fingerprint "${source_dir}")"
  build_arg_fingerprint="$(build_fingerprint_compute_build_arg_fingerprint)"

  {
    printf 'schema:laravel-build-input-fingerprint:v1\n'
    printf 'source_tree:%s\n' "${source_tree_fingerprint}"
    printf 'build_args:%s\n' "${build_arg_fingerprint}"
  } | build_fingerprint_checksum_stdin
}

build_state_value() {
  local state_file="$1"
  local key="$2"

  [[ -f "${state_file}" ]] || return 0
  grep -m1 "^${key}=" "${state_file}" 2>/dev/null | cut -d= -f2- || true
}

write_build_state() {
  local state_file="$1"
  local fingerprint="$2"
  local version="$3"
  local image="$4"
  local build_mode="${5:-}"
  local runtime_mode="${6:-}"
  local runtime_image="${7:-}"

  {
    printf 'BUILD_INPUT_FINGERPRINT=%s\n' "${fingerprint}"
    printf 'BUILD_VERSION=%s\n' "${version}"
    printf 'BUILD_IMAGE=%s\n' "${image}"
    printf 'BUILD_MODE=%s\n' "${build_mode}"
    printf 'BUILD_RUNTIME_MODE=%s\n' "${runtime_mode}"
    printf 'BUILD_RUNTIME_IMAGE=%s\n' "${runtime_image}"
    printf 'BUILD_UPDATED_AT=%s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
  } > "${state_file}"
}

docker_image_exists() {
  local image_ref="$1"
  docker image inspect "${image_ref}" >/dev/null 2>&1
}

tag_image_if_needed() {
  local source_ref="$1"
  local target_ref="$2"

  [[ "${source_ref}" == "${target_ref}" ]] || docker tag "${source_ref}" "${target_ref}" >/dev/null
}

BUILD_SKIP_REASON=""

prepare_build_image_from_state() {
  local current_fingerprint="$1"
  local requested_version="$2"
  local state_file="$3"
  local requested_build_mode="${4:-}"
  local requested_runtime_mode="${5:-}"
  local requested_runtime_image="${6:-}"
  local state_fingerprint=""
  local state_version=""
  local state_build_mode=""
  local state_runtime_mode=""
  local state_runtime_image=""
  local requested_ref="${APP_IMAGE}:${requested_version}"
  local existing_ref=""

  BUILD_SKIP_REASON=""
  state_fingerprint="$(build_state_value "${state_file}" BUILD_INPUT_FINGERPRINT)"
  state_version="$(build_state_value "${state_file}" BUILD_VERSION)"
  state_build_mode="$(build_state_value "${state_file}" BUILD_MODE)"
  state_runtime_mode="$(build_state_value "${state_file}" BUILD_RUNTIME_MODE)"
  state_runtime_image="$(build_state_value "${state_file}" BUILD_RUNTIME_IMAGE)"

  [[ -n "${state_fingerprint}" ]] || return 1
  [[ "${state_fingerprint}" == "${current_fingerprint}" ]] || return 1
  [[ -n "${state_build_mode}" && "${state_build_mode}" == "${requested_build_mode}" ]] || return 1
  [[ -n "${state_runtime_mode}" && "${state_runtime_mode}" == "${requested_runtime_mode}" ]] || return 1
  [[ -n "${state_runtime_image}" && "${state_runtime_image}" == "${requested_runtime_image}" ]] || return 1

  if docker_image_exists "${requested_ref}"; then
    tag_image_if_needed "${requested_ref}" "${APP_IMAGE}:latest"
    BUILD_SKIP_REASON="fingerprint matched and image tag ${requested_version} already exists"
    return 0
  fi

  [[ -n "${state_version}" ]] || return 1
  existing_ref="${APP_IMAGE}:${state_version}"
  docker_image_exists "${existing_ref}" || return 1

  tag_image_if_needed "${existing_ref}" "${requested_ref}"
  tag_image_if_needed "${existing_ref}" "${APP_IMAGE}:latest"
  BUILD_SKIP_REASON="fingerprint matched and existing image ${state_version} was retagged to ${requested_version}"
  return 0
}
