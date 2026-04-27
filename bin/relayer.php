<?php

declare(strict_types=1);

/**
 * Long-running relayer process.
 *
 * Polls mirror.pending_jobs (populated by the indexer's AntibodyPublished /
 * AntibodySlashed handlers) and submits transactions to Mirror contracts on
 * each configured execution chain via scripts/mirror-send.mjs.
 *
 * Usage (Docker):
 *     docker compose run --rm relayer
 * Usage (host):
 *     php bin/relayer.php
 *
 * Required env vars:
 *     RELAYER_PRIVATE_KEY_<CHAIN>   one per chain in config/mirror-network.json
 *     SEPOLIA_RPC_URL               (or whatever rpcUrl placeholders the JSON references)
 *
 * Optional env vars:
 *     RELAYER_POLL_INTERVAL_MS      default 2000
 *     RELAYER_MAX_RETRIES           default 5
 *     RELAYER_BATCH_SIZE            default 10
 *     RELAYER_BACKOFF_BASE_MS       default 5000  (exponential: base * 2^(attempts-1))
 *     RELAYER_REAP_INTERVAL_SEC     default 60
 *     RELAYER_REAP_STALE_SEC        default 300
 *     RELAYER_MEMORY_CEILING_MB     default 256
 *     MIRROR_NETWORK_FILE           override path to mirror-network.json
 */

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use App\Models\Core\Db;
use App\Models\Core\MirrorNetworkRegistry;
use App\Models\Indexer\Console\Cadence;
use App\Models\Mirror\Brokers\PendingJobsBroker;
use App\Models\Mirror\Console\RelayerSupervisor;
use App\Models\Mirror\MirrorBridge;
use App\Models\Mirror\Workers\RelayerWorker;
use Dotenv\Dotenv;
use Zephyrus\Core\Config\Configuration;

Dotenv::createImmutable(ROOT_DIR)->safeLoad();
Db::applyDatabaseUrl();

$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml');
if ($config->database === null) {
    fwrite(STDERR, "relayer: no database section in config.yml\n");
    exit(1);
}

$db = Db::fromConfig($config->database);

$pollIntervalMs   = (int) (getenv('RELAYER_POLL_INTERVAL_MS') ?: 2000);
$maxRetries       = (int) (getenv('RELAYER_MAX_RETRIES')      ?: 5);
$batchSize        = (int) (getenv('RELAYER_BATCH_SIZE')       ?: 10);
$backoffBaseMs    = (int) (getenv('RELAYER_BACKOFF_BASE_MS')  ?: 5000);
$reapIntervalSec  = (int) (getenv('RELAYER_REAP_INTERVAL_SEC') ?: 60);
$reapStaleSec     = (int) (getenv('RELAYER_REAP_STALE_SEC')   ?: 300);
$memoryCeilingMb  = (int) (getenv('RELAYER_MEMORY_CEILING_MB') ?: 256);
$bridgeTimeoutSec = (int) (getenv('RELAYER_BRIDGE_TIMEOUT_SEC') ?: 90);

try {
    $networks = MirrorNetworkRegistry::default();
} catch (Throwable $e) {
    fwrite(STDERR, "relayer: failed to load mirror network registry: " . $e->getMessage() . "\n");
    exit(1);
}

$jobs   = new PendingJobsBroker($db);
$bridge = new MirrorBridge(
    scriptPath: ROOT_DIR . '/scripts/mirror-send.mjs',
    timeoutSeconds: $bridgeTimeoutSec,
);
$worker = new RelayerWorker(
    jobs: $jobs,
    networks: $networks,
    bridge: $bridge,
    maxRetries: $maxRetries,
    backoffBaseMs: $backoffBaseMs,
    batchSize: $batchSize,
);

$supervisor = new RelayerSupervisor(
    db: $db,
    worker: $worker,
    jobs: $jobs,
    cadence: new Cadence(),
    pollIntervalMs: $pollIntervalMs,
    reapIntervalSec: $reapIntervalSec,
    reapStaleSec: $reapStaleSec,
    memoryCeilingMb: $memoryCeilingMb,
);

exit($supervisor->run());
