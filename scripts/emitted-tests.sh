#!/usr/bin/env bash
# Runs the GWT tests the generator EMITS (not the generator's own unit tests).
# Generating code is not enough — a scenario emitter can ship green-looking code
# whose assertions are wrong (e.g. over-asserting undeclared event fields). This
# gate generates every example's `symfony-eventsourcingdb` target (a pure decider,
# no database or SDK needed) into a temp dir and actually executes its emitted
# PHPUnit tests, so a broken emission fails loudly instead of silently.
#
# Needs a PHP with mbstring (phpunit requires it) — run it in the php container,
# same as `composer test`:
#   docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c \
#     'docker-php-ext-install mbstring >/dev/null && composer test:emitted'
set -euo pipefail

cd "$(dirname "$0")/.."

PHPUNIT="vendor/bin/phpunit"
[ -x "$PHPUNIT" ] || { echo "phpunit not found at $PHPUNIT — run composer install" >&2; exit 1; }

WORK="$(mktemp -d)"
BOOTSTRAP="$WORK/autoload.php"
trap 'rm -rf "$WORK"' EXIT

# Minimal PSR-4 autoloader for a generated app: the emitted decider tests are pure
# PHP, so they run without composer install / Symfony / EventSourcingDB / Mongo.
cat > "$BOOTSTRAP" <<'PHP'
<?php
declare(strict_types=1);
$root = getenv('GEN_APP_ROOT');
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['App\\Tests\\' => $root . '/tests/', 'App\\' => $root . '/src/'] as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; }
            return;
        }
    }
});
PHP

shopt -s nullglob
fail=0
ran=0
for app_dir in examples/*/; do
    [ -f "${app_dir}esdmgen.yaml" ] || continue
    app="$(basename "$app_dir")"
    out="$WORK/$app"

    if ! php bin/esdmgen generate "$app_dir" -o "$out" --target symfony-eventsourcingdb --skip-lint >/dev/null; then
        echo "$app: GENERATION FAILED"
        fail=1
        continue
    fi

    tests_dir="$out/symfony-esdb/tests"
    if [ ! -d "$tests_dir" ] || [ -z "$(find "$tests_dir" -name '*.php' -print -quit 2>/dev/null)" ]; then
        echo "$app: no emitted GWT tests (no feature/*.esdm.yaml scenarios)"
        continue
    fi

    if GEN_APP_ROOT="$out/symfony-esdb" php "$PHPUNIT" \
        --no-configuration --bootstrap "$BOOTSTRAP" --colors=never "$tests_dir" >"$WORK/out.txt" 2>&1; then
        echo "$app: emitted GWT tests PASS ($(grep -oE 'OK \([0-9]+ tests' "$WORK/out.txt" | head -1 || echo 'ok'))"
        ran=1
    else
        echo "$app: emitted GWT tests FAILED"
        cat "$WORK/out.txt"
        fail=1
    fi
done

[ "$ran" -eq 1 ] || { echo "No example emitted any GWT tests — expected at least one." >&2; fail=1; }
exit $fail
