<?php

declare(strict_types=1);

/**
 * Long-running indexer process.
 *
 * Reads on-chain events from the Galileo Registry contract, hydrates 0G
 * Storage envelopes, runs periodic maintenance jobs, and keeps Postgres
 * in sync with the deployed contract.
 *
 * Usage (Docker):
 *     docker compose run --rm indexer
 * Usage (host):
 *     php bin/indexer.php
 */

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use App\Models\Core\Db;
use App\Models\Core\NetworkConfig;
use App\Models\Event\Brokers\ContractEventBroker;
use App\Models\Indexer\Brokers\HydrationQueueBroker;
use App\Models\Indexer\Brokers\StateBroker;
use App\Models\Indexer\Brokers\TokenPriceCacheBroker;
use App\Models\Indexer\Pricing\MoralisPriceService;
use App\Models\Indexer\Chain\EventDecoder;
use App\Models\Indexer\Chain\JsonRpcClient;
use App\Models\Indexer\Chain\RegistryAbi;
use App\Models\Indexer\Console\Cadence;
use App\Models\Indexer\Console\Supervisor;
use App\Models\Indexer\Handlers\AntibodyMatchedHandler;
use App\Models\Indexer\Handlers\AntibodyPublishedHandler;
use App\Models\Indexer\Handlers\AntibodySlashedHandler;
use App\Models\Indexer\Handlers\AuditEventHandler;
use App\Models\Indexer\Handlers\CheckSettledHandler;
use App\Models\Indexer\Handlers\StakeReleasedHandler;
use App\Models\Indexer\Handlers\StakeSweptHandler;
use App\Models\Indexer\Storage\NodeBridge;
use App\Models\Indexer\Workers\BackfillBootstrap;
use App\Models\Indexer\Workers\EnsResolutionWorker;
use App\Models\Indexer\Workers\EventPoller;
use App\Models\Indexer\Workers\ExpirySweep;
use App\Models\Indexer\Workers\HydrationWorker;
use App\Models\Indexer\Workers\PricingRetryWorker;
use App\Models\Indexer\Workers\StatRefresher;
use App\Models\Network\Brokers\StatBroker;
use Dotenv\Dotenv;
use Ens\EnsService;
use Moralis\MoralisService;
use Zephyrus\Core\Config\Configuration;

Dotenv::createImmutable(ROOT_DIR)->safeLoad();
Db::applyDatabaseUrl();

$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml');
if ($config->database === null) {
    fwrite(STDERR, "indexer: no database section in config.yml\n");
    exit(1);
}

$db = Db::fromConfig($config->database);

$network           = NetworkConfig::galileo();
$pollIntervalMs    = (int) (getenv('INDEXER_POLL_INTERVAL_MS') ?: 2000);
$hydrationConc     = (int) (getenv('INDEXER_HYDRATION_CONCURRENCY') ?: 5);
$backfillChunk     = (int) (getenv('INDEXER_BACKFILL_CHUNK') ?: 5000);
$confirmations     = (int) (getenv('INDEXER_CONFIRMATIONS') ?: 2);
$deployBlock       = (int) (getenv('OG_REGISTRY_DEPLOY_BLOCK') ?: RegistryAbi::DEPLOY_BLOCK_DEFAULT);

$rpc = new JsonRpcClient($network->rpcUrl);
$abi = new RegistryAbi();
$decoder = new EventDecoder($abi);

$stateBroker = new StateBroker($db);
$queueBroker = new HydrationQueueBroker($db);
$contractEventBroker = new ContractEventBroker($db);
$statBroker = new StatBroker($db);

// Moralis pricing is optional — if no API key is configured the service is
// not constructed and handlers fall back to NULL value_at_risk_usd.
$moralisApiKey  = getenv('MORALIS_API_KEY') ?: '';
$priceCacheBroker = new TokenPriceCacheBroker($db);
$pricingService = $moralisApiKey === ''
    ? null
    : new MoralisPriceService(new MoralisService($moralisApiKey), $priceCacheBroker);
if ($pricingService === null) {
    fwrite(STDERR, "indexer: MORALIS_API_KEY not set; price lookups disabled\n");
}

$publishedHandler   = new AntibodyPublishedHandler($db, $queueBroker);
$checkSettledHandler = new CheckSettledHandler($db, $network, $pricingService);
$matchedHandler     = new AntibodyMatchedHandler($db, $network, $pricingService);
$stakeReleasedH     = new StakeReleasedHandler($db);
$stakeSweptH        = new StakeSweptHandler($db);
$slashedH           = new AntibodySlashedHandler($db);
$auditH             = new AuditEventHandler($contractEventBroker);

$poller = new EventPoller(
    rpc: $rpc,
    abi: $abi,
    decoder: $decoder,
    state: $stateBroker,
    chainId: $network->chainId,
    registryAddress: $network->registryAddress,
    publishedHandler: $publishedHandler,
    checkSettledHandler: $checkSettledHandler,
    antibodyMatchedHandler: $matchedHandler,
    stakeReleasedHandler: $stakeReleasedH,
    stakeSweptHandler: $stakeSweptH,
    antibodySlashedHandler: $slashedH,
    auditHandler: $auditH,
    confirmations: $confirmations,
    chunkSize: $backfillChunk,
);

$nodeBridge = new NodeBridge(
    scriptPath: ROOT_DIR . '/scripts/og-download.mjs',
    storageIndexerUrl: $network->storageIndexerUrl,
);

$hydrationWorker = new HydrationWorker($db, $queueBroker, $nodeBridge);
$expirySweep = new ExpirySweep($db);

// ENS is best-effort. Disable if no RPC URL.
$ensWorker = null;
if ($network->ensRpcUrl !== '') {
    try {
        $ens = new EnsService($network->ensRpcUrl);
        $ensWorker = new EnsResolutionWorker($db, $ens);
    } catch (Throwable $e) {
        fwrite(STDERR, "indexer: ENS disabled (" . $e->getMessage() . ")\n");
    }
}

$statRefresher = new StatRefresher($db, $statBroker);
$cadence = new Cadence();
$bootstrap = new BackfillBootstrap($stateBroker, $network->chainId, $deployBlock);

$pricingRetry = $pricingService === null
    ? null
    : new PricingRetryWorker($db, $pricingService);

$supervisor = new Supervisor(
    bootstrap: $bootstrap,
    poller: $poller,
    hydration: $hydrationWorker,
    expiry: $expirySweep,
    ens: $ensWorker,
    statRefresher: $statRefresher,
    cadence: $cadence,
    pricingRetry: $pricingRetry,
    pollIntervalMs: $pollIntervalMs,
    maxHydrationJobs: $hydrationConc,
);

exit($supervisor->run());
