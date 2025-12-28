#!/usr/bin/env bash
# Template Processing Library
# Provides functions for processing templates with placeholder replacement

set -euo pipefail

##########################################
# Process a template file with variable substitution
# Compatible with Bash 3.2+ (macOS default)
# Arguments:
#   $1 - Template file path
#   $2 - Output file path
#   $3... - Variable assignments in format KEY=VALUE
# Returns:
#   0 on success, 1 on failure
#######################################
process_template() {
    local template_file="$1"
    local output_file="$2"
    shift 2

    # Check template exists
    if [[ ! -f "$template_file" ]]; then
        echo "❌ Template file not found: $template_file"
        return 1
    fi

    # Create output directory if needed
    local output_dir
    output_dir="$(dirname "$output_file")"
    mkdir -p "$output_dir"

    # Process template
    local content
    content=$(cat "$template_file")

    # Replace all placeholders using provided variables
    for arg in "$@"; do
        if [[ "$arg" =~ ^([A-Z_]+)=(.*)$ ]]; then
            local key="${BASH_REMATCH[1]}"
            local value="${BASH_REMATCH[2]}"
            local placeholder="{{{${key}}}}"

            # Check if value contains newlines (multi-line)
            if [[ "$value" == *$'\n'* ]]; then
                # Multi-line value - use awk for proper handling
                # Save multi-line value to temp file to avoid shell escaping issues
                local temp_value_file="/tmp/template_value_$$_${RANDOM}"
                echo -n "$value" > "$temp_value_file"

                # Use awk to replace placeholder with file contents, preserving newlines
                local temp_output="/tmp/template_output_$$_${RANDOM}"
                echo "$content" > /tmp/template_content_$$

                awk -v placeholder="$placeholder" -v value_file="$temp_value_file" '
                    BEGIN {
                        # Read the entire replacement value from file
                        replacement = ""
                        while ((getline line < value_file) > 0) {
                            if (replacement != "") replacement = replacement "\n"
                            replacement = replacement line
                        }
                        close(value_file)
                    }
                    {
                        # Find and replace placeholder with multi-line content
                        line_out = $0
                        idx = index(line_out, placeholder)
                        if (idx > 0) {
                            # Extract leading whitespace before placeholder
                            before = substr(line_out, 1, idx - 1)
                            after = substr(line_out, idx + length(placeholder))
                            line_out = before replacement after
                        }
                        print line_out
                    }
                ' /tmp/template_content_$$ > "$temp_output"

                content=$(cat "$temp_output")

                # Clean up temp files
                rm -f "$temp_value_file" "$temp_output" /tmp/template_content_$$
            else
                # Single-line value - use sed (faster)
                # Escape special characters for sed
                local escaped_value
                escaped_value=$(echo "$value" | sed 's/[\/&]/\\&/g')

                # Replace all occurrences
                content=$(echo "$content" | sed "s|${placeholder}|${escaped_value}|g")
            fi
        fi
    done

    # Write output
    echo "$content" > "$output_file"

    # Verify no unresolved placeholders remain
    if echo "$content" | grep -q '{{{[A-Z_]*}}}'; then
        echo "⚠️  Warning: Unresolved placeholders found in $output_file"
        echo "$content" | grep -o '{{{[A-Z_]*}}}' | sort -u | sed 's/^/   - /'
        # Return 0 to avoid crashing set -e scripts; warnings are informative
        return 0
    fi

    return 0
}

#######################################
# Extract all placeholders from a template
# Arguments:
#   $1 - Template file path
# Returns:
#   List of unique placeholders (one per line)
#######################################
extract_placeholders() {
    local template_file="$1"

    if [[ ! -f "$template_file" ]]; then
        echo "❌ Template file not found: $template_file" >&2
        return 1
    fi

    grep -oh '{{{[A-Z_]*}}}' "$template_file" | sort -u | sed 's/{{{//; s/}}}//'
}

#######################################
# Validate that all required variables are provided
# Arguments:
#   $1 - Template file path
#   $2... - Variable assignments in format KEY=VALUE
# Returns:
#   0 if all required vars present, 1 if missing vars
#######################################
validate_template_vars() {
    local template_file="$1"
    shift

    # Extract required placeholders
    local required_vars
    required_vars=$(extract_placeholders "$template_file")

    # Build a string of all provided variable keys
    local provided_keys=""
    for arg in "$@"; do
        if [[ "$arg" =~ ^([A-Z_]+)=.*$ ]]; then
            provided_keys="${provided_keys} ${BASH_REMATCH[1]} "
        fi
    done

    # Check for missing variables
    local missing=()
    while IFS= read -r var; do
        if [[ ! " $provided_keys " =~ " $var " ]]; then
            missing+=("$var")
        fi
    done <<< "$required_vars"

    if [[ ${#missing[@]} -gt 0 ]]; then
        echo "❌ Missing required variables for template: $template_file"
        printf '   - %s\n' "${missing[@]}"
        return 1
    fi

    return 0
}

#######################################
# Process multiple templates in batch
# Arguments:
#   $1 - Template directory
#   $2 - Output directory
#   $3... - Variable assignments in format KEY=VALUE
# Returns:
#   0 on success, 1 on failure
#######################################
process_template_directory() {
    local template_dir="$1"
    local output_dir="$2"
    shift 2

    if [[ ! -d "$template_dir" ]]; then
        echo "❌ Template directory not found: $template_dir"
        return 1
    fi

    local processed=0
    local failed=0

    # Find all .template files
    while IFS= read -r template_file; do
        local rel_path="${template_file#$template_dir/}"
        local output_file="${output_dir}/${rel_path%.template}"

        echo "Processing: $rel_path -> ${output_file#$output_dir/}"

        if process_template "$template_file" "$output_file" "$@"; then
            ((processed++))
        else
            ((failed++))
        fi
    done < <(find "$template_dir" -type f -name "*.template")

    echo
    echo "Processed: $processed templates, $failed failed"

    return $([[ $failed -eq 0 ]] && echo 0 || echo 1)
}

#######################################
# Create backup of existing file
# Arguments:
#   $1 - File path to backup
# Returns:
#   0 on success, 1 on failure
#######################################
backup_file() {
    local file="$1"

    if [[ ! -f "$file" ]]; then
        return 0  # Nothing to backup
    fi

    local timestamp
    timestamp=$(date +%Y%m%d-%H%M%S)
    local backup_file="${file}.backup-${timestamp}"

    cp "$file" "$backup_file"
    echo "📦 Created backup: $backup_file"

    return 0
}

#######################################
# Safe template processing with backup
# Arguments:
#   $1 - Template file path
#   $2 - Output file path
#   $3 - Force overwrite (true/false)
#   $4... - Variable assignments
# Returns:
#   0 on success, 1 on failure
#######################################
safe_process_template() {
    local template_file="$1"
    local output_file="$2"
    local force="${3:-false}"
    shift 3

    # Check if output exists
    if [[ -f "$output_file" ]] && [[ "$force" != "true" ]]; then
        echo "⚠️  File exists: $output_file"
        read -p "   Overwrite? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "   Skipped."
            return 0
        fi
    fi

    # Create backup if file exists
    if [[ -f "$output_file" ]]; then
        backup_file "$output_file"
    fi

    # Process template
    process_template "$template_file" "$output_file" "$@"
}

#######################################
# Test suite for template processing
# Run with: bash -c 'source lib/template-processor.sh && test_template_processing'
#######################################
test_template_processing() {
    local test_dir="/tmp/template-test-$$"
    mkdir -p "$test_dir"

    echo "Running template processing tests..."
    echo

    # Create test template
    cat > "$test_dir/test.template" << 'EOF'
Project: {{{PROJECT_NAME}}}
Display: {{{PROJECT_DISPLAY_NAME}}}
Registry: {{{REGISTRY_HOST}}}
EOF

    # Test 1: Basic processing
    echo "Test 1: Basic template processing"
    if process_template "$test_dir/test.template" "$test_dir/output1.txt" \
        "PROJECT_NAME=my-api" \
        "PROJECT_DISPLAY_NAME=My API" \
        "REGISTRY_HOST=ghcr.io"; then

        if ! grep -q '{{{' "$test_dir/output1.txt"; then
            echo "✅ Test 1 passed: No unresolved placeholders"
        else
            echo "❌ Test 1 failed: Unresolved placeholders remain"
        fi
    else
        echo "❌ Test 1 failed: Processing error"
    fi

    # Test 2: Extract placeholders
    echo "Test 2: Extract placeholders"
    local placeholders
    placeholders=$(extract_placeholders "$test_dir/test.template")
    if echo "$placeholders" | grep -q "PROJECT_NAME" && \
       echo "$placeholders" | grep -q "PROJECT_DISPLAY_NAME" && \
       echo "$placeholders" | grep -q "REGISTRY_HOST"; then
        echo "✅ Test 2 passed: All placeholders extracted"
    else
        echo "❌ Test 2 failed: Missing placeholders"
    fi

    # Test 3: Validation
    echo "Test 3: Variable validation"
    if ! validate_template_vars "$test_dir/test.template" \
        "PROJECT_NAME=test" 2>/dev/null; then
        echo "✅ Test 3 passed: Validation detected missing vars"
    else
        echo "❌ Test 3 failed: Validation should have failed"
    fi

    # Cleanup
    rm -rf "$test_dir"

    echo
    echo "✅ Template processing tests completed"
}
