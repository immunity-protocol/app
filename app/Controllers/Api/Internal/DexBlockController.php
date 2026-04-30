<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Core\Db;
use App\Models\Core\SepoliaDexConfig;
use App\Models\Demo\Services\DexBlockIngestor;
use App\Models\Indexer\Brokers\TokenPriceCacheBroker;
use App\Models\Indexer\Chain\JsonRpcClient;
use App\Models\Indexer\Pricing\MoralisPriceService;
use Moralis\MoralisService;
use Throwable;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Post;

/**
 * Endpoint the /dex front-end POSTs to after a swap reverts on the protected
 * pool. Verifies the failed Sepolia tx came from our hook and persists
 * synthetic check_event + block_event rows so antibody stats and the
 * network-wide value-protected counter pick up pool reverts.
 */
final class DexBlockController extends Controller
{
    #[Post('/dex/blocked-swap')]
    public function ingest(): Response
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $txHash = is_array($body) ? (string) ($body['txHash'] ?? '') : '';
        if ($txHash === '') {
            return Response::json(['error' => 'txHash required'], 400);
        }

        try {
            $cfg = SepoliaDexConfig::default();
            $rpc = new JsonRpcClient($cfg->rpcUrl);
            // Separate archive-enabled client for the historical eth_call replay
            // inside the ingestor. Reuse $rpc when the URLs match so we don't
            // open a redundant connection in dev.
            $probeRpc = $cfg->probeRpcUrl !== '' && $cfg->probeRpcUrl !== $cfg->rpcUrl
                ? new JsonRpcClient($cfg->probeRpcUrl)
                : $rpc;
            $apiKey = (string) (getenv('MORALIS_API_KEY') ?: '');
            $moralis = new MoralisService($apiKey);
            $db = Db::current();
            $cache = new TokenPriceCacheBroker($db);
            $prices = new MoralisPriceService($moralis, $cache);
            $ingestor = new DexBlockIngestor($cfg, $rpc, $probeRpc, $prices, $db);
            $result = $ingestor->ingest($txHash);
            return Response::json($result, 200);
        } catch (Throwable $e) {
            return Response::json([
                'ingested' => false,
                'reason'   => 'server-error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
