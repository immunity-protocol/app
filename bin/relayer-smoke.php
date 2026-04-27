<?php

declare(strict_types=1);

/**
 * Manual smoke test for the relayer end-to-end.
 *
 * Runs a single RelayerWorker::tick() against the live mirror.pending_jobs
 * queue, then prints a summary plus the latest job rows. Use this to verify
 * connectivity to a Mirror chain (RPC URL, relayer key, mirror address) and
 * to confirm the Node helper signs/submits cleanly without spinning up the
 * long-running supervisor.
 *
 * Usage (Docker):
 *     docker compose exec indexer php bin/relayer-smoke.php
 * Usage (host):
 *     php bin/relayer-smoke.php
 *
 * Optional flags:
 *     --batch=N    process up to N jobs in this tick (default 5)
 *     --pending    list pending jobs and exit (no tick)
 */

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use App\Models\Core\Db;
use App\Models\Core\MirrorNetworkRegistry;
use App\Models\Mirror\Brokers\PendingJobsBroker;
use App\Models\Mirror\MirrorBridge;
use App\Models\Mirror\Workers\RelayerWorker;
use Dotenv\Dotenv;
use Zephyrus\Core\Config\Configuration;

Dotenv::createImmutable(ROOT_DIR)->safeLoad();
Db::applyDatabaseUrl();

$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml');
if ($config->database === null) {
    fwrite(STDERR, "relayer-smoke: no database section in config.yml\n");
    exit(1);
}

$batch = 5;
$pendingOnly = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $batch = max(1, (int) $m[1]);
    } elseif ($arg === '--pending') {
        $pendingOnly = true;
    } else {
        fwrite(STDERR, "relayer-smoke: unknown flag '$arg'\n");
        exit(2);
    }
}

$db   = Db::fromConfig($config->database);
$jobs = new PendingJobsBroker($db);

$counts = $db->selectOne(
    "SELECT
        sum((status='pending')::int)    AS pending,
        sum((status='in_flight')::int)  AS in_flight,
        sum((status='sent')::int)       AS sent,
        sum((status='confirmed')::int)  AS confirmed,
        sum((status='failed')::int)     AS failed
     FROM mirror.pending_jobs"
);
echo "queue: pending=" . (int) ($counts->pending ?? 0)
   . " in_flight=" . (int) ($counts->in_flight ?? 0)
   . " sent=" . (int) ($counts->sent ?? 0)
   . " confirmed=" . (int) ($counts->confirmed ?? 0)
   . " failed=" . (int) ($counts->failed ?? 0)
   . "\n";

if ($pendingOnly) {
    $rows = iterator_to_array((function () use ($db) {
        $st = $db->query(
            "SELECT id, encode(keccak_id, 'hex') AS keccak_hex, target_chain_id, job_type, attempts, last_error
               FROM mirror.pending_jobs
              WHERE status = 'pending'
              ORDER BY enqueued_at ASC
              LIMIT 20"
        );
        while (($r = $st->fetch(\PDO::FETCH_OBJ)) !== false) {
            yield $r;
        }
    })());
    foreach ($rows as $r) {
        echo sprintf("  job#%d chain=%d type=%-15s attempts=%d keccak=0x%s%s\n",
            (int) $r->id, (int) $r->target_chain_id, (string) $r->job_type, (int) $r->attempts,
            (string) $r->keccak_hex,
            $r->last_error ? "  err=$r->last_error" : '');
    }
    exit(0);
}

try {
    $networks = MirrorNetworkRegistry::default();
} catch (Throwable $e) {
    fwrite(STDERR, "relayer-smoke: failed to load mirror network registry: " . $e->getMessage() . "\n");
    exit(1);
}

$bridge = new MirrorBridge(scriptPath: ROOT_DIR . '/scripts/mirror-send.mjs');
$worker = new RelayerWorker(
    jobs: $jobs,
    networks: $networks,
    bridge: $bridge,
    maxRetries: 5,
    backoffBaseMs: 5000,
    batchSize: $batch,
);

echo "running one tick (batch=$batch)...\n";
$start = microtime(true);
$stats = $worker->tick();
$elapsed = number_format(microtime(true) - $start, 2);
echo "tick complete in {$elapsed}s: " . json_encode($stats) . "\n";

$latest = $db->query(
    "SELECT id, encode(keccak_id, 'hex') AS keccak_hex, target_chain_id, job_type, status,
            attempts, encode(tx_hash, 'hex') AS tx_hex, last_error
       FROM mirror.pending_jobs
      ORDER BY enqueued_at DESC
      LIMIT 5"
);
echo "latest jobs:\n";
while (($r = $latest->fetch(\PDO::FETCH_OBJ)) !== false) {
    echo sprintf("  job#%d chain=%d type=%-15s status=%-10s attempts=%d tx=0x%s%s\n",
        (int) $r->id, (int) $r->target_chain_id, (string) $r->job_type,
        (string) $r->status, (int) $r->attempts,
        $r->tx_hex ?: '(none)',
        $r->last_error ? "\n      err=$r->last_error" : '');
}
