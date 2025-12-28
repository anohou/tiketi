#!/usr/bin/env bash
# Feature: Image Digest Verification
# Verifies pulled image matches expected digest from CI/CD


FEATURE_NAME="image-digest"

#######################################
# Check if feature is enabled
#######################################
image_digest_is_enabled() {
    local enabled=$(get_config "features.${FEATURE_NAME}.enabled" 2>/dev/null || echo "false")
    [[ "$enabled" == "true" ]]
}

#######################################
# Initialize feature
#######################################
image_digest_init() {
    image_digest_is_enabled || return 0

    log "INFO" "[${FEATURE_NAME}] Initializing..."

    # Load configuration
    export SKIP_LOCAL_BUILDS=$(get_config "features.${FEATURE_NAME}.skip_for_local_builds" 2>/dev/null || echo "true")

    log "SUCCESS" "[${FEATURE_NAME}] Initialized"
}

#######################################
# Validate configuration
#######################################
image_digest_validate() {
    image_digest_is_enabled || return 0
    return 0
}

#######################################
# Hook: post-build
# Verify image digest after pulling from registry
#######################################
image_digest_hook_post_build() {
    image_digest_is_enabled || return 0

    local expected_digest="${IMAGE_DIGEST:-}"

    # Skip if no digest provided (local builds or no CI/CD artifact)
    if [[ -z "$expected_digest" ]]; then
        if [[ "$SKIP_LOCAL_BUILDS" == "true" ]]; then
            log "INFO" "[${FEATURE_NAME}] No digest provided - skipping verification (local build)"
            return 0
        else
            log "WARNING" "[${FEATURE_NAME}] No digest provided but verification required"
            return 1
        fi
    fi

    log "INFO" "[${FEATURE_NAME}] Verifying image digest..."

    # Get actual digest of pulled image
    local actual_digest=$(docker inspect --format='{{.Id}}' "${FULL_REGISTRY_PATH}:${DEPLOY_ENV}" 2>/dev/null || echo "")

    if [[ -z "$actual_digest" ]]; then
        log "ERROR" "[${FEATURE_NAME}] Could not get image digest"
        return 1
    fi

    # Compare digests
    if [[ "$actual_digest" != "$expected_digest" ]]; then
        log "ERROR" "[${FEATURE_NAME}] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        log "ERROR" "[${FEATURE_NAME}]  IMAGE DIGEST MISMATCH"
        log "ERROR" "[${FEATURE_NAME}] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        log "ERROR" "[${FEATURE_NAME}] Expected: $expected_digest"
        log "ERROR" "[${FEATURE_NAME}] Actual:   $actual_digest"
        log "ERROR" "[${FEATURE_NAME}] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        log "ERROR" "[${FEATURE_NAME}] Image verification failed - potential security issue"
        return 1
    fi

    log "SUCCESS" "[${FEATURE_NAME}] Image digest verified: ${actual_digest:0:24}..."
}

#######################################
# Cleanup
#######################################
image_digest_cleanup() {
    return 0
}
