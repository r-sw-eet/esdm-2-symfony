#!/usr/bin/env bash
# Generates every example app from THIS repo's own source models
# (examples/<app>/model, driven by examples/<app>/esdmgen.yaml). Self-contained:
# uses this repo's own tools/esdm lint binary — no sibling repo required.
#
# The generator is pure PHP and runs locally; only the *generated* apps need
# Docker + pdo_pgsql. BPMN-authored apps generate from their already-mapped
# model/ — run `bin/esdmgen bpmn:map` separately when the .bpmn changes.
#
# Two modes:
#   (default)  write each app's output into examples/<app>/generated/ (gitignored)
#   --check    smoke gate: generate into a temp dir and fail loudly on any error
#              or suspiciously empty tree; nothing is written to the working tree.
#
# Both modes generate all apps and report every failure (they don't stop at the
# first). Re-run after any change under src/Adapter/.
#
# Usage: scripts/examples.sh [--check]
set -euo pipefail

cd "$(dirname "$0")/.."

CHECK=0
[ "${1:-}" = "--check" ] && CHECK=1

export ESDM_BIN="${ESDM_BIN:-$PWD/tools/esdm}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

shopt -s nullglob
apps=(examples/*/)
if [ ${#apps[@]} -eq 0 ]; then
    echo "No example apps found under examples/ — nothing to generate." >&2
    exit 1
fi

fail=0
for app_dir in "${apps[@]}"; do
    [ -f "${app_dir}esdmgen.yaml" ] || continue
    app="$(basename "$app_dir")"

    if [ "$CHECK" -eq 1 ]; then
        gen_out="$WORK/$app/symfony"
        out_args=(-o "$WORK/$app")
    else
        gen_out="${app_dir}generated/symfony"
        out_args=()   # esdmgen.yaml's `out: generated` → examples/<app>/generated/symfony
    fi

    if ! php bin/esdmgen generate "$app_dir" "${out_args[@]}" >/dev/null; then
        echo "$app: GENERATION FAILED"
        fail=1
        continue
    fi

    count="$(find "$gen_out" -type f 2>/dev/null | wc -l)"
    if [ "$count" -lt 10 ]; then
        echo "$app: SUSPICIOUSLY EMPTY ($count files)"
        fail=1
        continue
    fi
    echo "$app: $count files"
done

exit $fail
