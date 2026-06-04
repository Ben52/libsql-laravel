<?php
/**
 * Standalone load/concurrency test for libsql-laravel against real Turso.
 *
 * Usage:
 *   php tests/Stress/load/turso_load_test.php
 *
 * Credentials are read from .stress-creds.env (STRESS_TURSO_URL, STRESS_TURSO_TOKEN).
 *
 * Concurrency model: spawns 8 parallel PHP sub-processes (proc_open) each
 * running the same script in "worker" mode. This avoids macOS objc-after-fork
 * crashes that occur when the libsql Rust extension has already loaded threads
 * before pcntl_fork() is called.
 */

declare(strict_types=1);

// Suppress deprecations from turso/libsql package (PHP 8.4 nullable param deprecations).
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// ──────────────────────────────────────────────────────────────────────────────
// Config
// ──────────────────────────────────────────────────────────────────────────────
const TABLE_NAME     = 'load_stress_test';
const WORKER_COUNT   = 8;
const OPS_PER_WORKER = 50; // 8 × 50 = 400 total ops
const READ_RATIO     = 0.4; // 40 % reads, 60 % writes

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────
function loadCreds(): array
{
    $f = __DIR__ . '/../../../.stress-creds.env';
    if (!is_file($f)) {
        fwrite(STDERR, "ERROR: .stress-creds.env not found at {$f}\n");
        exit(1);
    }
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
            $out[$m[1]] = $m[2];
        }
    }
    return $out;
}

function nowMs(): float
{
    return microtime(true) * 1000.0;
}

function percentile(array $sorted, float $p): float
{
    if (empty($sorted)) return 0.0;
    $idx = (int) ceil(($p / 100.0) * count($sorted)) - 1;
    return $sorted[max(0, $idx)];
}

// ──────────────────────────────────────────────────────────────────────────────
// WORKER MODE – run when this script is re-invoked with --worker argument
// ──────────────────────────────────────────────────────────────────────────────
if (isset($argv[1]) && $argv[1] === '--worker') {
    require __DIR__ . '/../../../vendor/autoload.php';

    $workerId = (int) $argv[2];
    $url      = $argv[3];
    $token    = $argv[4];
    $ops      = (int) $argv[5];
    $readRatio = (float) $argv[6];

    $latencies   = [];
    $errors      = 0;
    $errorMsgs   = [];
    $fatal       = null;

    try {
        $db   = new \Libsql\Database(url: $url, authToken: $token);
        $conn = $db->connect();
    } catch (\Throwable $e) {
        echo json_encode([
            'worker'    => $workerId,
            'latencies' => [],
            'errors'    => 1,
            'fatal'     => 'connect: ' . $e->getMessage(),
        ]);
        exit(1);
    }

    for ($i = 0; $i < $ops; $i++) {
        // First op is always a write so reads have something to read
        $isRead = ($i > 0) && (lcg_value() < $readRatio);

        $t0 = nowMs();
        try {
            if ($isRead) {
                $offset = random_int(0, max(0, $i - 1));
                // Connection::query(sql, params) uses bind() then query() internally
                $conn->query(
                    'SELECT id, val FROM ' . TABLE_NAME
                    . ' WHERE worker_id = ? ORDER BY id LIMIT 5 OFFSET ?',
                    [$workerId, $offset]
                )->fetchArray();
            } else {
                $val = 'w' . $workerId . '_op' . $i . '_' . bin2hex(random_bytes(6));
                // Connection::execute(sql, params) uses bind() then execute() internally
                $conn->execute(
                    'INSERT INTO ' . TABLE_NAME
                    . ' (worker_id, op_idx, val, created_at) VALUES (?, ?, ?, ?)',
                    [$workerId, $i, $val, date('Y-m-d H:i:s')]
                );
            }
            $latencies[] = round(nowMs() - $t0, 3);
        } catch (\Throwable $e) {
            $errors++;
            $errorMsgs[] = "op{$i}: " . substr($e->getMessage(), 0, 200);
            $latencies[] = round(nowMs() - $t0, 3);
        }
    }

    echo json_encode([
        'worker'    => $workerId,
        'latencies' => $latencies,
        'errors'    => $errors,
        'errorMsgs' => $errorMsgs,
    ]);
    exit($errors > 0 ? 1 : 0);
}

// ──────────────────────────────────────────────────────────────────────────────
// ORCHESTRATOR MODE
// ──────────────────────────────────────────────────────────────────────────────
require __DIR__ . '/../../../vendor/autoload.php';

$creds = loadCreds();
$url   = $creds['STRESS_TURSO_URL']   ?? '';
$token = $creds['STRESS_TURSO_TOKEN'] ?? '';

if (empty($url) || empty($token)) {
    fwrite(STDERR, "ERROR: STRESS_TURSO_URL / STRESS_TURSO_TOKEN not set in .stress-creds.env\n");
    exit(1);
}

echo "=== libsql-laravel Turso Load Test ===\n";
echo "URL    : {$url}\n";
echo "Workers: " . WORKER_COUNT . "   Ops/worker: " . OPS_PER_WORKER . "   Total: " . (WORKER_COUNT * OPS_PER_WORKER) . "\n";
echo "Read ratio: " . (READ_RATIO * 100) . "%\n\n";

// ── Create table ──────────────────────────────────────────────────────────────
echo "Creating table " . TABLE_NAME . " ...\n";
$setupDb   = new \Libsql\Database(url: $url, authToken: $token);
$setupConn = $setupDb->connect();
$setupConn->execute(
    'CREATE TABLE IF NOT EXISTS ' . TABLE_NAME . ' ('
    . 'id         INTEGER PRIMARY KEY AUTOINCREMENT,'
    . 'worker_id  INTEGER NOT NULL,'
    . 'op_idx     INTEGER NOT NULL,'
    . 'val        TEXT    NOT NULL,'
    . 'created_at TEXT    NOT NULL'
    . ')'
);
echo "Table ready.\n\n";

// ── Spawn workers ─────────────────────────────────────────────────────────────
$php      = PHP_BINARY;
$script   = __FILE__;
$handles  = [];
$pipes    = [];

$wallStart = microtime(true);

for ($w = 0; $w < WORKER_COUNT; $w++) {
    // Use array form of proc_open to avoid shell-quoting issues with paths
    // that contain spaces (e.g. Herd's PHP binary path).
    $cmdArgs = [
        $php,
        $script,
        '--worker',
        (string) $w,
        $url,
        $token,
        (string) OPS_PER_WORKER,
        (string) READ_RATIO,
    ];

    $descriptor = [
        0 => ['pipe', 'r'],   // stdin
        1 => ['pipe', 'w'],   // stdout (JSON result)
        2 => ['pipe', 'w'],   // stderr
    ];

    $proc = proc_open($cmdArgs, $descriptor, $workerPipes);
    if ($proc === false) {
        fwrite(STDERR, "ERROR: proc_open failed for worker {$w}\n");
        exit(1);
    }

    fclose($workerPipes[0]); // close stdin
    $handles[$w] = $proc;
    $pipes[$w]   = [$workerPipes[1], $workerPipes[2]];

    echo "  spawned worker {$w}\n";
}

echo "\nWaiting for workers to finish...\n";

// ── Collect results ───────────────────────────────────────────────────────────
$allLatencies = [];
$totalErrors  = 0;
$workerFatals = [];

for ($w = 0; $w < WORKER_COUNT; $w++) {
    [$stdout, $stderr] = $pipes[$w];

    $out = stream_get_contents($stdout);
    $err = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);

    $exitCode = proc_close($handles[$w]);

    echo "  worker {$w} exited with status {$exitCode}";
    if ($err !== '') {
        // Strip deprecation noise; only print real errors
        $errClean = trim(preg_replace('/^Deprecated:.*$/m', '', $err));
        if ($errClean !== '') {
            echo " [stderr: " . substr($errClean, 0, 200) . "]";
        }
    }
    echo "\n";

    $data = json_decode($out, true);
    if ($data === null) {
        $workerFatals[] = "Worker {$w}: JSON decode failed (raw: " . substr($out, 0, 100) . ")";
        $totalErrors++;
        continue;
    }

    if (!empty($data['fatal'])) {
        $workerFatals[] = "Worker {$w}: {$data['fatal']}";
        $totalErrors++;
    }

    if (!empty($data['errorMsgs'])) {
        foreach ($data['errorMsgs'] as $msg) {
            $workerFatals[] = "Worker {$w} error: {$msg}";
        }
    }

    foreach (($data['latencies'] ?? []) as $lat) {
        $allLatencies[] = (float) $lat;
    }
    $totalErrors += (int) ($data['errors'] ?? 0);
}

$wallDuration = microtime(true) - $wallStart;

sort($allLatencies);
$totalOps   = count($allLatencies);
$throughput = $totalOps / max($wallDuration, 0.001);

$p50 = percentile($allLatencies, 50);
$p95 = percentile($allLatencies, 95);
$p99 = percentile($allLatencies, 99);

// ── Drop table ────────────────────────────────────────────────────────────────
echo "\nDropping table " . TABLE_NAME . " ...\n";
try {
    $setupConn->execute('DROP TABLE IF EXISTS ' . TABLE_NAME);
    echo "Table dropped.\n";
} catch (\Throwable $e) {
    echo "WARNING: could not drop table: " . $e->getMessage() . "\n";
}

// ── Print report ─────────────────────────────────────────────────────────────
echo "\n";
echo str_repeat('=', 50) . "\n";
echo "  LOAD TEST RESULTS\n";
echo str_repeat('=', 50) . "\n";
printf("  Total ops      : %d\n", $totalOps);
printf("  Errors         : %d\n", $totalErrors);
printf("  Wall clock     : %.2f s\n", $wallDuration);
printf("  Throughput     : %.1f ops/sec\n", $throughput);
printf("  Latency p50    : %.1f ms\n", $p50);
printf("  Latency p95    : %.1f ms\n", $p95);
printf("  Latency p99    : %.1f ms\n", $p99);
echo str_repeat('=', 50) . "\n";

if (!empty($workerFatals)) {
    echo "\nFATAL ERRORS:\n";
    foreach ($workerFatals as $msg) {
        echo "  {$msg}\n";
    }
}

echo "\nMACHINE_RESULT:" . json_encode([
    'ran'             => true,
    'operations'      => $totalOps,
    'errors'          => $totalErrors,
    'p50ms'           => round($p50, 2),
    'p95ms'           => round($p95, 2),
    'p99ms'           => round($p99, 2),
    'throughputPerSec'=> round($throughput, 2),
    'wallClockSec'    => round($wallDuration, 2),
]) . "\n";

exit($totalErrors > 0 ? 1 : 0);
