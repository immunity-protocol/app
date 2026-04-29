<?php

declare(strict_types=1);

namespace App\Models\Demo\Services;

use App\Models\Core\SepoliaDexConfig;
use App\Models\Indexer\Chain\JsonRpcClient;
use App\Models\Indexer\Pricing\MoralisPriceService;
use kornrunner\Keccak;
use Throwable;
use Zephyrus\Data\Database;

/**
 * Verifies a failed Sepolia tx, decodes the Immunity hook revert, and
 * persists synthetic event.check_event + event.block_event rows so pool
 * reverts feed the antibody stats and the network value-protected counter.
 *
 * Trust model: anyone can POST any tx hash to the public endpoint, but we
 * only ingest if the on-chain receipt confirms (a) the tx failed, (b) the
 * `to` is the configured Sepolia swap router, and (c) the revert data
 * matches one of our hook's three custom errors. UNIQUE(tx_hash, log_index)
 * guarantees idempotency.
 */
final class DexBlockIngestor
{
    private const HOOK_ERROR_SIGS = [
        'TokenBlocked'  => 'TokenBlocked(address,bytes32)',
        'SenderBlocked' => 'SenderBlocked(address,bytes32)',
        'OriginBlocked' => 'OriginBlocked(address,bytes32)',
    ];

    /** @var array<string, string> selector hex (0x + 8 hex) → error name */
    private array $errorSelectorMap;

    public function __construct(
        private readonly SepoliaDexConfig $cfg,
        private readonly JsonRpcClient $rpc,
        private readonly MoralisPriceService $prices,
        private readonly Database $db,
    ) {
        $this->errorSelectorMap = [];
        foreach (self::HOOK_ERROR_SIGS as $name => $sig) {
            $selector = '0x' . substr(Keccak::hash($sig, 256), 0, 8);
            $this->errorSelectorMap[strtolower($selector)] = $name;
        }
    }

    /**
     * @return array{ingested: bool, reason?: string, valueProtectedUsd?: string, errorName?: string, entryAttached?: bool}
     */
    public function ingest(string $txHash): array
    {
        $txHashLower = strtolower($txHash);
        if (!preg_match('/^0x[0-9a-f]{64}$/', $txHashLower)) {
            return ['ingested' => false, 'reason' => 'invalid-tx-hash'];
        }

        // Pre-check: skip the RPC if we already saw this tx.
        $existing = $this->db->selectValue(
            "SELECT 1 FROM event.block_event WHERE tx_hash = decode(?, 'hex') LIMIT 1",
            [substr($txHashLower, 2)]
        );
        if ($existing !== null) {
            return ['ingested' => false, 'reason' => 'duplicate'];
        }

        $receipt = $this->rpc->call('eth_getTransactionReceipt', [$txHashLower]);
        if (!is_array($receipt) || ($receipt['status'] ?? '0x1') !== '0x0') {
            return ['ingested' => false, 'reason' => 'not-failed'];
        }

        $tx = $this->rpc->call('eth_getTransactionByHash', [$txHashLower]);
        if (!is_array($tx)) {
            return ['ingested' => false, 'reason' => 'tx-not-found'];
        }

        $to = strtolower((string) ($tx['to'] ?? ''));
        if ($to !== strtolower($this->cfg->swapRouterAddress)) {
            return ['ingested' => false, 'reason' => 'not-swap-router'];
        }

        // Re-run the call against the historical state to surface the revert
        // data (eth_getTransactionReceipt does not include it in standard
        // JSON-RPC).
        $blockHex = (string) ($receipt['blockNumber'] ?? '0x0');
        $callParams = array_filter([
            'from'  => $tx['from']  ?? null,
            'to'    => $tx['to'],
            'data'  => $tx['input'] ?? '0x',
            'gas'   => $tx['gas']   ?? null,
            'value' => $tx['value'] ?? '0x0',
        ], static fn ($v) => $v !== null);
        $revertData = $this->probeRevertData($callParams, $blockHex);
        if ($revertData === null) {
            return ['ingested' => false, 'reason' => 'no-revert-data'];
        }
        $decoded = $this->decodeHookError($revertData);
        if ($decoded === null) {
            return ['ingested' => false, 'reason' => 'not-immunity-revert'];
        }

        $swapDecoded = $this->decodeSwap((string) ($tx['input'] ?? '0x'));
        if ($swapDecoded === null) {
            return ['ingested' => false, 'reason' => 'unparseable-swap-input'];
        }

        $tokenAddrSpent = $swapDecoded['zeroForOne']
            ? strtolower($this->cfg->currency0)
            : strtolower($this->cfg->currency1);

        $amountIn = $swapDecoded['amountIn']; // decimal string
        $valueUsd = $this->prices->priceUsd($tokenAddrSpent, $this->cfg->chainId, $amountIn, 18)
            ?? '0.000000';

        $entryId = $this->findEntryIdByMatcher($decoded['target'], $this->cfg->chainId)
            ?? $this->findEntryIdByKeccak($decoded['keccakId']);

        $blockTimestamp = $this->fetchBlockTimestamp($blockHex);
        $occurredAt = gmdate('Y-m-d\TH:i:s\Z', $blockTimestamp ?? time());

        $fromAddr = strtolower((string) ($tx['from'] ?? '0x0000000000000000000000000000000000000000'));
        $agentId = substr($fromAddr, 0, 128);

        try {
            $this->db->query(
                "INSERT INTO event.check_event (
                    agent_id, tx_kind, chain_id, decision, confidence,
                    matched_entry_id, cache_hit, tee_used,
                    value_at_risk_usd, pricing_failed,
                    token_address, token_amount, origin_chain_id,
                    occurred_at, tx_hash, log_index
                 ) VALUES (
                    ?, 'pool_swap', ?, 'block'::event.check_decision, 100,
                    ?, false, false,
                    ?::numeric, false,
                    decode(?, 'hex'), ?::numeric, ?,
                    ?::timestamptz, decode(?, 'hex'), 0
                 )
                 ON CONFLICT (tx_hash, log_index) DO NOTHING",
                [
                    $agentId,
                    $this->cfg->chainId,
                    $entryId,
                    $valueUsd,
                    substr($tokenAddrSpent, 2),
                    $amountIn,
                    $this->cfg->chainId,
                    $occurredAt,
                    substr($txHashLower, 2),
                ]
            );

            $checkEventId = (int) $this->db->selectValue(
                "SELECT id FROM event.check_event WHERE tx_hash = decode(?, 'hex') AND log_index = 0",
                [substr($txHashLower, 2)],
                0
            );
            if ($checkEventId === 0) {
                return ['ingested' => false, 'reason' => 'check-event-insert-failed'];
            }

            if ($entryId !== null) {
                $this->db->query(
                    "INSERT INTO event.block_event (
                        check_event_id, entry_id, agent_id, value_protected_usd,
                        pricing_failed, token_address, token_amount, origin_chain_id,
                        tx_hash_attempt, chain_id, occurred_at, tx_hash, log_index
                     ) VALUES (
                        ?, ?, ?, ?::numeric,
                        false, decode(?, 'hex'), ?::numeric, ?,
                        decode(?, 'hex'), ?, ?::timestamptz, decode(?, 'hex'), 0
                     )
                     ON CONFLICT (tx_hash, log_index) DO NOTHING",
                    [
                        $checkEventId,
                        $entryId,
                        $agentId,
                        $valueUsd,
                        substr($tokenAddrSpent, 2),
                        $amountIn,
                        $this->cfg->chainId,
                        substr($txHashLower, 2),
                        $this->cfg->chainId,
                        $occurredAt,
                        substr($txHashLower, 2),
                    ]
                );
            }
        } catch (Throwable $e) {
            return ['ingested' => false, 'reason' => 'db-error: ' . $e->getMessage()];
        }

        return [
            'ingested'          => true,
            'valueProtectedUsd' => $valueUsd,
            'errorName'         => $decoded['errorName'],
            'entryAttached'     => $entryId !== null,
        ];
    }

    /**
     * Try a fresh eth_call against the historical state to surface revert
     * data. ethers / geth typically return the data inside the error object
     * on a reverting eth_call. We swallow exceptions and pull the data out
     * with a regex.
     *
     * @param array<string, mixed> $callParams
     */
    private function probeRevertData(array $callParams, string $blockHex): ?string
    {
        try {
            $this->rpc->call('eth_call', [$callParams, $blockHex]);
            return null;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (preg_match('/0x[0-9a-fA-F]{8,}/', $msg, $m)) {
                return strtolower($m[0]);
            }
            return null;
        }
    }

    /**
     * @return array{errorName: string, target: string, keccakId: string} | null
     */
    private function decodeHookError(string $hex): ?array
    {
        $hex = strtolower($hex);
        if (!str_starts_with($hex, '0x') || strlen($hex) < 10) {
            return null;
        }
        $selector = substr($hex, 0, 10);
        $name = $this->errorSelectorMap[$selector] ?? null;
        if ($name === null) {
            return null;
        }
        $body = substr($hex, 10);
        if (strlen($body) < 128) {
            return null;
        }
        return [
            'errorName' => $name,
            'target'    => '0x' . substr($body, 24, 40),
            'keccakId'  => '0x' . substr($body, 64, 64),
        ];
    }

    /**
     * Decode swapExactTokensForTokens calldata to (amountIn, zeroForOne).
     *
     * @return array{amountIn: string, zeroForOne: bool} | null
     */
    private function decodeSwap(string $input): ?array
    {
        if (strlen($input) < 10 || !str_starts_with($input, '0x')) {
            return null;
        }
        $body = substr($input, 10);
        if (strlen($body) < 64 * 3) {
            return null;
        }
        $amountInHex = substr($body, 0, 64);
        $zeroForOneHex = substr($body, 128, 64);
        return [
            'amountIn'   => self::hexToDecimal('0x' . $amountInHex),
            'zeroForOne' => substr($zeroForOneHex, -1) === '1',
        ];
    }

    private function findEntryIdByMatcher(string $target, int $chainId): ?int
    {
        $chainHex  = str_pad(dechex($chainId), 64, '0', STR_PAD_LEFT);
        $targetHex = str_pad(strtolower(substr($target, 2)), 64, '0', STR_PAD_LEFT);
        $packed = hex2bin($chainHex . $targetHex);
        if ($packed === false) {
            return null;
        }
        $matcherHash = Keccak::hash($packed, 256);
        $id = $this->db->selectValue(
            "SELECT id FROM antibody.entry WHERE primary_matcher_hash = decode(?, 'hex') LIMIT 1",
            [$matcherHash]
        );
        return $id === null ? null : (int) $id;
    }

    private function findEntryIdByKeccak(string $keccakId): ?int
    {
        if (!preg_match('/^0x[0-9a-f]{64}$/', strtolower($keccakId))) {
            return null;
        }
        $id = $this->db->selectValue(
            "SELECT id FROM antibody.entry WHERE keccak_id = decode(?, 'hex') LIMIT 1",
            [substr(strtolower($keccakId), 2)]
        );
        return $id === null ? null : (int) $id;
    }

    private function fetchBlockTimestamp(string $blockHex): ?int
    {
        try {
            $block = $this->rpc->call('eth_getBlockByNumber', [$blockHex, false]);
            if (is_array($block) && isset($block['timestamp'])) {
                return JsonRpcClient::hexToInt((string) $block['timestamp']);
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    private static function hexToDecimal(string $hex): string
    {
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }
        if (function_exists('gmp_init')) {
            return (string) gmp_strval(gmp_init($hex, 16), 10);
        }
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcmul($dec, '16');
            $dec = bcadd($dec, (string) hexdec($hex[$i]));
        }
        return $dec;
    }
}
