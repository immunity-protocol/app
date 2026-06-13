<?php

declare(strict_types=1);

/**
 * Long-running indexer process.
 *
 * Reads on-chain events from the Base Sepolia Immunity contract suite (Registry,
 * Reputation, PublisherRegistrar, ChallengeManager, CREVerdictReceiver,
 * ProtectedSet, L2Registry) over a single cursor, hydrates antibody evidence
 * from Lighthouse/IPFS, runs periodic maintenance jobs, and keeps Postgres in
 * sync. The per-chain Mirror pollers (A3 relayer track) run alongside unchanged.
 *
 * Usage (Docker):
 *     docker compose run --rm indexer
 * Usage (host):
 *     php bin/indexer.php
 */

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use App\Models\Core\Db;
use App\Models\Core\MirrorNetworkRegistry;
use App\Models\Core\NetworkConfig;
use App\Models\Event\Brokers\ContractEventBroker;
use App\Models\Indexer\Brokers\HydrationQueueBroker;
use App\Models\Indexer\Brokers\StateBroker;
use App\Models\Indexer\Brokers\TokenPriceCacheBroker;
use App\Models\Indexer\Pricing\MoralisPriceService;
use App\Models\Indexer\Chain\BaseChainAbi;
use App\Models\Indexer\Chain\EventDecoder;
use App\Models\Indexer\Chain\JsonRpcClient;
use App\Models\Indexer\Chain\MirrorAbi;
use App\Models\Indexer\Handlers\AntibodyMatchedHandler;
use App\Models\Indexer\Handlers\AntibodyMirroredHandler;
use App\Models\Indexer\Handlers\AntibodyPublishedHandler;
use App\Models\Indexer\Handlers\AntibodySlashedHandler;
use App\Models\Indexer\Handlers\AntibodyUnmirroredHandler;
use App\Models\Indexer\Handlers\AuditEventHandler;
use App\Models\Indexer\Handlers\BondLedgerHandler;
use App\Models\Indexer\Handlers\ChallengeHandler;
use App\Models\Indexer\Handlers\CheckSettledHandler;
use App\Models\Indexer\Handlers\EnsIngestHandler;
use App\Models\Indexer\Handlers\ExpiredHandler;
use App\Models\Indexer\Handlers\MaturedHandler;
use App\Models\Indexer\Handlers\ProtectedSetHandler;
use App\Models\Indexer\Handlers\PublisherIdentityHandler;
use App\Models\Indexer\Handlers\ReputationHandler;
use App\Models\Indexer\Handlers\SeededHandler;
use App\Models\Indexer\Storage\LighthouseFetcher;
use App\Models\Indexer\Workers\BackfillBootstrap;
use App\Models\Indexer\Workers\EnsResolutionWorker;
use App\Models\Indexer\Workers\EventPoller;
use App\Models\Indexer\Workers\HydrationWorker;
use App\Models\Indexer\Workers\PricingRetryWorker;
use App\Models\Indexer\Workers\StatRefresher;
use App\Models\Network\Brokers\StatBroker;
use Dotenv\Dotenv;
use Ens\EnsService;
use Moralis\MoralisService;
use Zephyrus\Core\Config\Configuration;
use App\Models\Indexer\Console\Cadence;
use App\Models\Indexer\Console\Supervisor;

Dotenv::createImmutable(ROOT_DIR)->safeLoad();
Db::applyDatabaseUrl();

$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml');
if ($config->database === null) {
    fwrite(STDERR, "indexer: no database section in config.yml\n");
    exit(1);
}

$db = Db::fromConfig($config->database);

$network        = NetworkConfig::baseSepolia();
$pollIntervalMs = (int) (getenv('INDEXER_POLL_INTERVAL_MS') ?: 15000);
$hydrationConc  = (int) (getenv('INDEXER_HYDRATION_CONCURRENCY') ?: 5);
$backfillChunk  = (int) (getenv('INDEXER_BACKFILL_CHUNK') ?: 5000);
$confirmations  = (int) (getenv('INDEXER_CONFIRMATIONS') ?: 2);

$rpc = new JsonRpcClient($network->rpcUrl);
$baseAbi = BaseChainAbi::fromContracts($network->contracts());
$decoder = new EventDecoder($baseAbi);

$stateBroker = new StateBroker($db);
$queueBroker = new HydrationQueueBroker($db);
$contractEventBroker = new ContractEventBroker($db);
$statBroker = new StatBroker($db);

// Moralis is optional. The price service is always constructed so the
// INDEXER_PRICE_OVERRIDES env-var path works on its own (mock tokens pinned
// to $1). Without a key, only overrides resolve; non-overridden tokens come
// back null and the retry worker picks them up if a later override matches.
$moralisApiKey  = getenv('MORALIS_API_KEY') ?: '';
$priceCacheBroker = new TokenPriceCacheBroker($db);
$pricingService = new MoralisPriceService(
    $moralisApiKey === '' ? null : new MoralisService($moralisApiKey),
    $priceCacheBroker,
);
if ($moralisApiKey === '') {
    fwrite(STDERR, "indexer: MORALIS_API_KEY not set; pricing in overrides-only mode\n");
}

// ---------------------------------------------------------------------------
// Base Sepolia handlers (Phase 1: antibody lifecycle + reputation + identity).
// ---------------------------------------------------------------------------
$publishedHandler = new AntibodyPublishedHandler($db, $queueBroker);
$seededHandler    = new SeededHandler($db);
$maturedHandler   = new MaturedHandler($db);
$expiredHandler   = new ExpiredHandler($db);
$slashedHandler   = new AntibodySlashedHandler($db);
$checkedHandler   = new CheckSettledHandler($db, $network, $pricingService);
$matchedHandler   = new AntibodyMatchedHandler($db, $network, $pricingService);
$bondLedger       = new BondLedgerHandler($db);
$reputation       = new ReputationHandler($db);
$identity         = new PublisherIdentityHandler($db);
$audit            = new AuditEventHandler($contractEventBroker);
// Phase 2: challenges/jury, protected set, ENS subname mirror.
$challenge        = new ChallengeHandler($db);
$protectedSet     = new ProtectedSetHandler($db);
$ensIngest        = new EnsIngestHandler($db);

$baseHandlers = [
    'Registry.Published'      => fn (array $d) => $publishedHandler->handle($d),
    'Registry.Seeded'         => fn (array $d) => $seededHandler->handle($d),
    'Registry.Matured'        => fn (array $d) => $maturedHandler->handle($d),
    'Registry.Expired'        => fn (array $d) => $expiredHandler->handle($d),
    'Registry.Retired'        => fn (array $d) => $expiredHandler->handle($d),
    'Registry.Slashed'        => fn (array $d) => $slashedHandler->handle($d),
    'Registry.Checked'        => fn (array $d) => $checkedHandler->handle($d),
    'Registry.Matched'        => fn (array $d) => $matchedHandler->handle($d),
    'Registry.BondLocked'     => fn (array $d) => $bondLedger->handleBondLocked($d),
    'Registry.BondReleased'   => fn (array $d) => $bondLedger->handleBondReleased($d),
    'Registry.FeesEscrowed'   => fn (array $d) => $bondLedger->handleFeesEscrowed($d),
    'Registry.FeesReleased'   => fn (array $d) => $bondLedger->handleFeesReleased($d),
    'Registry.FeesClawedBack' => fn (array $d) => $bondLedger->handleFeesClawedBack($d),

    'Reputation.Matured'        => fn (array $d) => $reputation->handleMatured($d),
    'Reputation.ChallengeWon'   => fn (array $d) => $reputation->handleChallengeWon($d),
    'Reputation.Slashed'        => fn (array $d) => $reputation->handleSlashed($d),
    'Reputation.GenesisGranted' => fn (array $d) => $reputation->handleGenesisGranted($d),

    'PublisherRegistrar.Registered'       => fn (array $d) => $identity->handleRegistered($d),
    'PublisherRegistrar.Deregistered'     => fn (array $d) => $identity->handleDeregistered($d),
    'PublisherRegistrar.ReputationSynced' => fn (array $d) => $identity->handleReputationSynced($d),

    // Phase 2: challenges/jury.
    'Registry.ChallengeOpened'             => fn (array $d) => $challenge->handleChallengeOpened($d),
    'Registry.ChallengeTimedOut'           => fn (array $d) => $challenge->handleChallengeTimedOut($d),
    'ChallengeManager.VerdictRequested'    => fn (array $d) => $challenge->handleVerdictRequested($d),
    'ChallengeManager.Escalated'           => fn (array $d) => $challenge->handleEscalated($d),
    'ChallengeManager.Resolved'            => fn (array $d) => $challenge->handleResolved($d),
    'CREVerdictReceiver.VerdictReceived'   => fn (array $d) => $challenge->handleVerdictReceived($d),

    // Phase 2: protected set + ENS subname/text mirror.
    'ProtectedSet.ProtectedUpdated'        => fn (array $d) => $protectedSet->handle($d),
    'L2Registry.SubnodeCreated'            => fn (array $d) => $ensIngest->handleSubnodeCreated($d),
    'L2Registry.TextChanged'               => fn (array $d) => $ensIngest->handleTextChanged($d),
];
// Operator/treasury balance movements: audit log only (KPI, optional).
foreach (['Registry.Deposited', 'Registry.Withdrew', 'Registry.TreasuryWithdrawn'] as $auditKey) {
    $baseHandlers[$auditKey] = fn (array $d) => $audit->handle($d);
}

$basePoller = new EventPoller(
    rpc: $rpc,
    decoder: $decoder,
    state: $stateBroker,
    chainId: $network->chainId,
    addresses: $baseAbi->addresses(),
    handlers: $baseHandlers,
    confirmations: $confirmations,
    chunkSize: $backfillChunk,
);
$basePollerEntry = [
    'poller'      => $basePoller,
    'intervalSec' => max(1, (int) round($pollIntervalMs / 1000)),
];
$baseBootstrap = new BackfillBootstrap($stateBroker, $network->chainId, $network->deployBlock);

// ---------------------------------------------------------------------------
// Mirror pollers (A3 relayer track) — unchanged; one poller + bootstrap per
// configured Mirror chain. Each Mirror exposes the same event surface, so we
// share MirrorAbi and rebind per-chain handlers.
// ---------------------------------------------------------------------------
$mirrorNetworks = MirrorNetworkRegistry::default();
$mirrorAbi      = new MirrorAbi();
$mirrorDecoder  = new EventDecoder($mirrorAbi);
$mirrorPollers  = [];
$mirrorBoots    = [];
foreach ($mirrorNetworks->all() as $chain) {
    if ($chain->rpcUrl === '') {
        fwrite(STDERR, "indexer: skipping chain {$chain->chainId} ({$chain->name}): no RPC URL configured\n");
        continue;
    }
    $mirrorRpc   = new JsonRpcClient($chain->rpcUrl);
    $mirroredH   = new AntibodyMirroredHandler($db, $chain->chainId, $chain->name);
    $unmirroredH = new AntibodyUnmirroredHandler($db, $chain->chainId);
    $mirrorHandlers = [
        'AntibodyMirrored'   => fn (array $d) => $mirroredH->handle($d),
        'AntibodyUnmirrored' => fn (array $d) => $unmirroredH->handle($d),
    ];
    foreach (['AddressBlocked', 'CallPatternBlocked', 'BytecodeBlocked', 'GraphTaintAdded',
              'SemanticPatternAdded', 'AdminTransferred', 'RelayerSet'] as $auxName) {
        $mirrorHandlers[$auxName] = fn (array $d) => $audit->handle($d);
    }
    $mirrorPoller = new EventPoller(
        rpc: $mirrorRpc,
        decoder: $mirrorDecoder,
        state: $stateBroker,
        chainId: $chain->chainId,
        addresses: $chain->mirrorAddress,
        handlers: $mirrorHandlers,
        confirmations: $confirmations,
        chunkSize: $backfillChunk,
    );
    $chainIntervalMs = $chain->pollIntervalMs ?? $pollIntervalMs;
    $mirrorPollers[] = [
        'poller'      => $mirrorPoller,
        'intervalSec' => max(1, (int) round($chainIntervalMs / 1000)),
    ];
    $mirrorBoots[] = new BackfillBootstrap($stateBroker, $chain->chainId, $chain->deployBlock);
}

// Evidence hydration from Lighthouse/IPFS (CIDv0 reconstructed from the
// on-chain digest).
$fetcher = new LighthouseFetcher();
$hydrationWorker = new HydrationWorker($db, $queueBroker, $fetcher);

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
$pricingRetry = new PricingRetryWorker($db, $pricingService);

$supervisor = new Supervisor(
    bootstraps: array_merge([$baseBootstrap], $mirrorBoots),
    pollers: array_merge([$basePollerEntry], $mirrorPollers),
    hydration: $hydrationWorker,
    ens: $ensWorker,
    statRefresher: $statRefresher,
    cadence: $cadence,
    pricingRetry: $pricingRetry,
    pollIntervalMs: $pollIntervalMs,
    maxHydrationJobs: $hydrationConc,
);

exit($supervisor->run());
