<?php

declare(strict_types=1);

/**
 * C4 conformance runner for the symfony targets — implements the runner contract in
 * ../esdm-extensions/conformance/README.md. This is the ORACLE runner: `--record`
 * rewrites the golden observation files (review those diffs like spec changes).
 *
 * Usage: php scripts/conformance.php <app> [--keep] [--skip-gen] [--record]
 */

use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/../vendor/autoload.php';

const TARGETS = [
    'symfony' => ['target' => 'symfony-patchlevel-postgres', 'slug' => 'symfony', 'port' => 18120],
    'symfony-esdb' => ['target' => 'symfony-eventsourcingdb', 'slug' => 'symfony-esdb', 'port' => 18121],
];
const API_INTERNAL = 8000;
const READY_TIMEOUT = 600;
const CONVERGE_TIMEOUT = 90;

$repo = dirname(__DIR__);
$ws = dirname($repo);
$ext = $ws . '/esdm-extensions/conformance';
$work = $repo . '/.c4work';

function say(string $msg): void
{
    fwrite(STDOUT, '[c4:esdm-2-symfony] ' . $msg . "\n");
}

function sh(string $cmd, ?string $cwd = null): void
{
    $prefix = $cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' : '';
    exec($prefix . $cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException("command failed ($code): $cmd\n" . implode("\n", array_slice($out, -8)));
    }
}

/** @return array{0: int, 1: mixed} */
function http(int $port, string $method, string $path, ?array $body = null): array
{
    // Plain streams — the host PHP has neither curl nor mbstring.
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\n",
        'content' => $body !== null ? json_encode($body) : '',
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents("http://127.0.0.1:$port/$path", false, $ctx);
    if ($raw === false) {
        throw new RuntimeException("no response from :$port/$path");
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    $parsed = json_decode($raw, true);
    return [$status, json_last_error() === JSON_ERROR_NONE ? $parsed : $raw];
}

function resolvePlaceholders(mixed $value, array $captures): mixed
{
    if (is_string($value)) {
        foreach ($captures as $k => $v) {
            $value = str_replace('$' . $k, $v, $value);
        }
        return $value;
    }
    if (is_array($value)) {
        return array_map(fn ($v) => resolvePlaceholders($v, $captures), $value);
    }
    return $value;
}

function canonical(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            $value = array_map(canonical(...), $value);
            return '[' . implode(',', $value) . ']';
        }
        ksort($value);
        $parts = [];
        foreach ($value as $k => $v) {
            $parts[] = json_encode((string) $k) . ':' . canonical($v);
        }
        return '{' . implode(',', $parts) . '}';
    }
    $json = json_encode($value);

    return $json === false ? 'null' : $json;  // json_encode(0) is the FALSY string "0"
}

function camelKeys(string $key): string
{
    return preg_replace_callback('/_([a-z0-9])/', fn ($m) => strtoupper($m[1]), $key);
}

function canonEvent(string $name): string
{
    $parts = explode('.', $name);
    return strtolower(str_replace('_', '-', end($parts)));
}

function normalizeValue(mixed $value, array $idmap): mixed
{
    if (is_string($value)) {
        return $idmap[$value] ?? $value;
    }
    if (is_array($value)) {
        if (array_is_list($value)) {
            return array_map(fn ($v) => normalizeValue($v, $idmap), $value);
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[camelKeys((string) $k)] = normalizeValue($v, $idmap);
        }
        return $out;
    }
    return $value;
}

function sortRows(array $rows): array
{
    usort($rows, fn ($a, $b) => canonical($a) <=> canonical($b));
    return array_values($rows);
}

function runSteps(int $port, array $steps): array
{
    $captures = [];
    $out = [];
    foreach ($steps as $step) {
        if (isset($step['get'])) {
            $deadline = time() + ($step['poll_timeout'] ?? 45);
            do {
                [$status, $resp] = http($port, 'GET', (string) resolvePlaceholders($step['get'], $captures));
                $ok = is_array($resp) && array_is_list($resp) && count($resp) >= ($step['min_rows'] ?? 1);
            } while (($step['poll'] ?? false) && !$ok && time() <= $deadline && !usleep(1000000));
            if (isset($step['capture']) && is_array($resp) && $resp !== []) {
                $field = $step['capture_field'] ?? 'id';
                $rows = sortRows(array_filter($resp, is_array(...)));
                $fresh = array_values(array_filter($rows, fn ($r) => !in_array($r[$field] ?? null, $captures, true)));
                $val = ($fresh ?: $rows)[0][$field] ?? null;
                if (is_string($val)) {
                    $captures[$step['capture']] = $val;
                }
            }
            $out[] = ['step' => $step['name'], 'endpoint' => 'GET ' . $step['get'], 'status' => $status, 'body' => $resp];
            continue;
        }
        $body = resolvePlaceholders($step['body'] ?? null, $captures);
        [$status, $resp] = http($port, 'POST', $step['post'], $body);
        if (isset($step['capture']) && is_array($resp) && is_string($resp['id'] ?? null)) {
            $captures[$step['capture']] = $resp['id'];
        }
        $out[] = ['step' => $step['name'], 'endpoint' => 'POST ' . $step['post'], 'status' => $status, 'body' => $resp];
    }
    return [$out, $captures];
}

function readCheckpoints(int $port, array $checkpoints, array $captures): array
{
    $out = [];
    foreach ($checkpoints as $cp) {
        [$status, $resp] = http($port, 'GET', (string) resolvePlaceholders($cp['get'], $captures));
        $out[] = ['checkpoint' => $cp['name'], 'endpoint' => 'GET ' . $cp['get'], 'status' => $status, 'body' => $resp];
    }
    return $out;
}

function converge(int $port, array $checkpoints, array $captures): array
{
    $stable = 0;
    $last = null;
    $deadline = time() + CONVERGE_TIMEOUT;
    while (time() < $deadline) {
        $snap = canonical(readCheckpoints($port, $checkpoints, $captures));
        if ($snap === $last) {
            if (++$stable >= 2) {
                return readCheckpoints($port, $checkpoints, $captures);
            }
        } else {
            $stable = 0;
            $last = $snap;
        }
        sleep(1);
    }
    say('WARN: checkpoints did not stabilize in ' . CONVERGE_TIMEOUT . 's');
    return readCheckpoints($port, $checkpoints, $captures);
}

function normalizeAll(array $steps, array $checkpoints, array $captures): array
{
    $idmap = [];
    foreach ($captures as $k => $v) {
        $idmap[$v] = "\u{AB}$k\u{BB}";
    }
    $nsteps = [];
    foreach ($steps as $o) {
        $body = normalizeValue($o['body'], $idmap);
        if (is_array($body) && array_is_list($body)) {
            $body = sortRows($body);
        }
        $nsteps[] = ['step' => $o['step'], 'endpoint' => $o['endpoint'], 'status' => $o['status'], 'body' => $body];
    }
    $ncps = [];
    foreach ($checkpoints as $o) {
        $body = $o['body'];
        if ($o['checkpoint'] === 'events') {
            $rows = [];
            foreach (is_array($body) ? $body : [] as $r) {
                $rows[] = [
                    'aggregate' => strtolower((string) ($r['aggregate'] ?? '')),
                    'aggregateId' => $idmap[$r['aggregate_id'] ?? null] ?? ($r['aggregate_id'] ?? null),
                    'event' => canonEvent((string) ($r['event'] ?? '')),
                    'playhead' => $r['playhead'] ?? null,
                    'payload' => normalizeValue($r['payload'] ?? null, $idmap),
                ];
            }
            $body = $rows;
        } else {
            $body = normalizeValue($body, $idmap);
            if (is_array($body) && array_is_list($body)) {
                $body = sortRows($body);
            }
        }
        $ncps[] = ['checkpoint' => $o['checkpoint'], 'endpoint' => $o['endpoint'], 'status' => $o['status'], 'body' => $body];
    }
    return ['steps' => $nsteps, 'checkpoints' => $ncps];
}

function flattenValue(string $prefix, mixed $value, array &$out): void
{
    if (is_array($value) && !array_is_list($value)) {
        if ($value === []) {
            $out[$prefix] = (object) [];
            return;
        }
        foreach ($value as $k => $v) {
            flattenValue($prefix === '' ? (string) $k : "$prefix.$k", $v, $out);
        }
        return;
    }
    if (is_array($value)) {
        if ($value === []) {
            $out[$prefix] = [];
            return;
        }
        foreach ($value as $i => $v) {
            flattenValue($prefix . "[$i]", $v, $out);
        }
        return;
    }
    $out[$prefix] = $value;
}

function compare(array $mine, array $golden, array $registry, string $target): array
{
    $failures = [];
    $accepted = [];
    foreach (['steps' => 'step', 'checkpoints' => 'checkpoint'] as $kind => $nameKey) {
        foreach ($golden[$kind] as $i => $g) {
            $m = $mine[$kind][$i] ?? ['status' => null, 'body' => null];
            $endpoint = $g['endpoint'] . '#' . $g[$nameKey];
            $fg = [];
            $fm = [];
            flattenValue('', ['status' => $g['status'], 'body' => $g['body']], $fg);
            flattenValue('', ['status' => $m['status'], 'body' => $m['body']], $fm);
            foreach (array_unique(array_merge(array_keys($fg), array_keys($fm))) as $field) {
                $a = array_key_exists($field, $fg) ? canonical($fg[$field]) : '<absent>';
                $b = array_key_exists($field, $fm) ? canonical($fm[$field]) : '<absent>';
                if ($a === $b) {
                    continue;
                }
                $entry = ['endpoint' => $endpoint, 'field' => $field, 'golden' => $a, 'got' => $b];
                $registered = false;
                foreach ($registry as $reg) {
                    if (isset($reg['targets']) && !in_array($target, $reg['targets'], true)) {
                        continue;
                    }
                    if (fnmatch($reg['endpoint'], $endpoint) && fnmatch($reg['field'], $field)) {
                        $registered = true;
                        break;
                    }
                }
                if ($registered) {
                    $accepted[] = $entry;
                } else {
                    $failures[] = $entry;
                }
            }
        }
    }
    return [$failures, $accepted];
}

// ---------------------------------------------------------------- main

$args = array_slice($argv, 1);
$flags = array_filter($args, fn ($a) => str_starts_with($a, '--'));
$app = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')))[0] ?? null;
if ($app === null) {
    fwrite(STDERR, "usage: php scripts/conformance.php <app> [--keep] [--skip-gen] [--record]\n");
    exit(2);
}
$keep = in_array('--keep', $flags, true);
$skipGen = in_array('--skip-gen', $flags, true);
$record = in_array('--record', $flags, true);

$scenario = Yaml::parseFile("$ext/scenarios/$app.yaml");
$registry = Yaml::parseFile("$ext/registry.yaml")['divergences'] ?? [];
$goldenPath = "$ext/golden/$app.observations.json";

$exit = 0;
foreach (TARGETS as $tname => $tcfg) {
    if (!in_array($tname, $scenario['targets'], true)) {
        say("$tname: not in scenario targets — skipped");
        continue;
    }
    $appdir = "$work/$app/$tname";
    $stack = "$appdir/generated/{$tcfg['slug']}";
    $project = "c4-esdm-2-symfony-$app-$tname";
    $port = $tcfg['port'];

    if (!$skipGen) {
        sh('rm -rf ' . escapeshellarg($appdir) . ' && mkdir -p ' . escapeshellarg($appdir));
        say("$tname: generating");
        sh('ESDM_BIN=' . escapeshellarg("$repo/tools/esdm") . ' php ' . escapeshellarg("$repo/bin/esdmgen")
            . ' generate ' . escapeshellarg($appdir)
            . ' --target ' . escapeshellarg($tcfg['target'])
            . ' --model ' . escapeshellarg("$ws/{$scenario['model']}")
            . ' --out ' . escapeshellarg("$appdir/generated"));
        $compose = Yaml::parseFile("$stack/compose.yaml");
        foreach ($compose['services'] as $name => &$svc) {
            if ($name === 'api') {
                $svc['ports'] = ["127.0.0.1:$port:" . API_INTERNAL];
            } else {
                unset($svc['ports']);
            }
        }
        unset($svc);
        file_put_contents("$stack/compose.yaml", Yaml::dump($compose, 6, 2));
    }

    try {
        say("$tname: booting on :$port");
        sh("docker compose -p $project -f " . escapeshellarg("$stack/compose.yaml") . ' up -d --build --quiet-pull');
        $deadline = time() + READY_TIMEOUT;
        while (time() < $deadline) {
            try {
                if (http($port, 'GET', '_dev/catalog')[0] === 200) {
                    break;
                }
            } catch (Throwable) {
            }
            sleep(2);
        }
        if (time() >= $deadline) {
            throw new RuntimeException("$tname: api not ready in " . READY_TIMEOUT . 's');
        }
        say("$tname: running scenario");
        [$steps, $captures] = runSteps($port, $scenario['steps']);
        $cps = converge($port, $scenario['checkpoints'], $captures);
        $mine = normalizeAll($steps, $cps, $captures);
        file_put_contents("$appdir/observations.json", json_encode($mine, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($record && $tname === 'symfony') {
            file_put_contents($goldenPath, json_encode($mine, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            say("$tname: golden RECORDED -> $goldenPath");
            continue;
        }

        $golden = json_decode((string) file_get_contents($goldenPath), true);
        [$failures, $accepted] = compare($mine, $golden, $registry, $tname);
        foreach ($accepted as $d) {
            say("$tname: registered divergence {$d['endpoint']} {$d['field']}");
        }
        foreach ($failures as $d) {
            say("$tname: FAIL {$d['endpoint']} {$d['field']}: golden={$d['golden']} got={$d['got']}");
        }
        say("$tname: " . ($failures === [] ? 'PASS' : 'FAIL (' . count($failures) . ' unregistered divergences)'));
        $exit |= $failures === [] ? 0 : 1;
    } finally {
        if (!$keep) {
            sh("docker compose -p $project -f " . escapeshellarg("$stack/compose.yaml") . ' down -v --remove-orphans');
        }
    }
}

exit($exit);
