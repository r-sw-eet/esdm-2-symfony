#!/usr/bin/env bash
# C4 conformance (this repo's targets vs the golden answers in ../esdm-extensions/conformance).
# This runner is the ORACLE: pass --record to rewrite the golden files (review like a spec change).
set -euo pipefail
exec php "$(dirname "$0")/conformance.php" "$@"
