#!/usr/bin/env bash
set -Eeuo pipefail

# Test double: the first configured provider is unavailable while the second
# accepts both copy and verification operations.
remote="${3:-}"
[[ "${remote}" != first:* ]]
