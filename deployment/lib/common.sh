#!/usr/bin/env bash
# Common utilities used by scaffold libraries

set -euo pipefail

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'  # No Color

#######################################
# Print info message
# Arguments:
#   $* - Message to print
#######################################
print_info() {
    echo -e "${BLUE}ℹ${NC}  $*"
}

#######################################
# Print success message
# Arguments:
#   $* - Message to print
#######################################
print_success() {
    echo -e "${GREEN}✓${NC}  $*"
}

#######################################
# Print warning message
# Arguments:
#   $* - Message to print
#######################################
print_warning() {
    echo -e "${YELLOW}⚠${NC}  $*"
}

#######################################
# Print error message
# Arguments:
#   $* - Message to print
# Outputs:
#   To stderr
#######################################
print_error() {
    echo -e "${RED}✗${NC}  $*" >&2
}

#######################################
# Print step message
# Arguments:
#   $* - Message to print
#######################################
print_step() {
    echo -e "${CYAN}▸${NC} $*"
}
