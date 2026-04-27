<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use App\Models\Core\NetworkConfig;
use App\Models\Indexer\Pricing\MoralisPriceService;
use Zephyrus\Data\Database;

/**
 * CheckSettled(indexed address agent, indexed bytes32 antibodyId,
 *              bool wasMatch, uint256 fee, uint64 timestamp)
 *
 * One row per SDK check() call that is settled on chain. We translate to:
 *   - decision = wasMatch ? 'block' : 'allow'
 *   - cache_hit = (antibodyId != 0x000...)
 *   - matched_entry_id = lookup antibody.entry by keccak_id (NULL if unknown)
 *
 * tx_kind / tee_used / value_at_risk_usd are NULL for v1 (no SDK telemetry
 * channel yet). chain_id is the Galileo chain id.
 */
class CheckSettledHandler
{
    public function __construct(
        private readonly Database $db,
        private readonly NetworkConfig $network,
        private readonly ?MoralisPriceService $pricing = null,
    ) {
    }

    /**
     * @param array{event:string,args:array<string,mixed>,blockNumber:int,txHash:string,logIndex:int,address:string} $decoded
     * @return bool true if a new row was inserted
     */
    public function handle(array $decoded): bool
    {
        $a = $decoded['args'];
        $agentHex = strtolower(self::stripHex((string) $a['agent']));
        $antibodyIdHex = strtolower(self::stripHex((string) $a['antibodyId']));
        $cacheHit = !self::isZeroHex($antibodyIdHex);
        $decision = !empty($a['wasMatch']) ? 'block' : 'allow';

        $occurredAt = (int) $a['timestamp'];
        $txHashHex = strtolower(self::stripHex((string) $decoded['txHash']));

        // New telemetry fields are present post-redeploy. For the old contract
        // they're absent, in which case we skip pricing and write NULL — the
        // retry worker won't pick up rows that have nothing to price either.
        $valueAtRisk = null;
        $pricingFailed = false;
        $tokenAddress = isset($a['tokenAddress']) ? (string) $a['tokenAddress'] : null;
        $tokenAmount  = isset($a['tokenAmount'])  ? (string) $a['tokenAmount']  : null;
        $originChain  = isset($a['originChainId']) ? (int) $a['originChainId']  : 0;

        if (
            $this->pricing !== null
            && $tokenAddress !== null
            && $tokenAmount !== null
            && $tokenAmount !== '0'
            && $originChain !== 0
        ) {
            $valueAtRisk = $this->pricing->priceUsd($tokenAddress, $originChain, $tokenAmount);
            $pricingFailed = $valueAtRisk === null;
        }

        $row = $this->db->query(
            <<<'SQL'
            INSERT INTO event.check_event (
                agent_id, tx_kind, chain_id, decision, confidence,
                matched_entry_id, cache_hit, tee_used, value_at_risk_usd,
                pricing_failed, occurred_at, tx_hash, log_index
            )
            VALUES (
                ?, 'unknown', ?, ?::event.check_decision, NULL,
                CASE WHEN ? THEN
                    (SELECT id FROM antibody.entry WHERE keccak_id = ? LIMIT 1)
                ELSE NULL END,
                ?, false, ?,
                ?, to_timestamp(?), ?, ?
            )
            ON CONFLICT (tx_hash, log_index) DO NOTHING
            RETURNING id
            SQL,
            [
                '0x' . $agentHex,
                $this->network->chainId,
                $decision,
                $cacheHit ? 't' : 'f',
                '\\x' . $antibodyIdHex,
                $cacheHit ? 't' : 'f',
                $valueAtRisk,
                $pricingFailed ? 't' : 'f',
                $occurredAt,
                '\\x' . $txHashHex,
                (int) $decoded['logIndex'],
            ]
        );
        return $row->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }

    private static function isZeroHex(string $hex): bool
    {
        return $hex === '' || ltrim($hex, '0') === '';
    }
}
