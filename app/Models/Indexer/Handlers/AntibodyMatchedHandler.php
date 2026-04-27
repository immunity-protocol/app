<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use App\Models\Indexer\Chain\RegistryAbi;
use Zephyrus\Data\Database;

/**
 * AntibodyMatched(indexed bytes32 keccakId, indexed address agent,
 *                 indexed address publisher, address reviewer,
 *                 uint256 publisherReward, uint256 treasuryReward)
 *
 * Inserts an event.block_event row referencing the matched antibody and the
 * preceding CheckSettled in the same transaction; bumps publisher aggregates.
 *
 * value_protected_usd is set to 0 in v1 (no SDK telemetry channel yet).
 * tx_hash_attempt is NULL (the matched tx is the one carrying this event,
 * not a downstream attempt).
 */
class AntibodyMatchedHandler
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{event:string,args:array<string,mixed>,blockNumber:int,txHash:string,logIndex:int,address:string} $decoded
     */
    public function handle(array $decoded): bool
    {
        $a = $decoded['args'];
        $keccakIdHex = strtolower(self::stripHex((string) $a['keccakId']));
        $agentHex = strtolower(self::stripHex((string) $a['agent']));
        $publisherHex = strtolower(self::stripHex((string) $a['publisher']));
        $publisherReward = self::weiToUsdc((string) $a['publisherReward']);
        $txHashHex = strtolower(self::stripHex((string) $decoded['txHash']));

        $entryRow = $this->db->query(
            "SELECT id FROM antibody.entry WHERE keccak_id = ? LIMIT 1",
            ['\\x' . $keccakIdHex]
        );
        $entry = $entryRow->fetch(\PDO::FETCH_ASSOC);
        if ($entry === false) {
            // Antibody hasn't been observed yet (out-of-order replay). Skip;
            // the next backfill pass will repair it after AntibodyPublished
            // lands.
            return false;
        }
        $entryId = (int) $entry['id'];

        $checkRow = $this->db->query(
            "SELECT id FROM event.check_event WHERE tx_hash = ? ORDER BY log_index DESC LIMIT 1",
            ['\\x' . $txHashHex]
        );
        $check = $checkRow->fetch(\PDO::FETCH_ASSOC);
        $checkEventId = $check === false ? null : (int) $check['id'];
        if ($checkEventId === null) {
            // No CheckSettled in DB for this tx (CheckSettled may be processed
            // later). Insert a placeholder check_event so block_event FK holds.
            $insCheck = $this->db->query(
                "INSERT INTO event.check_event
                    (agent_id, tx_kind, chain_id, decision, confidence,
                     matched_entry_id, cache_hit, tee_used, value_at_risk_usd,
                     occurred_at, tx_hash, log_index)
                 VALUES (?, 'unknown', ?, 'block'::event.check_decision, NULL,
                     ?, true, false, NULL,
                     now(), ?, ?)
                 ON CONFLICT (tx_hash, log_index) DO NOTHING
                 RETURNING id",
                ['0x' . $agentHex, RegistryAbi::CHAIN_ID, $entryId, '\\x' . $txHashHex, -((int) $decoded['logIndex'])]
            );
            $r = $insCheck->fetch(\PDO::FETCH_ASSOC);
            $checkEventId = $r === false ? null : (int) $r['id'];
        }

        if ($checkEventId === null) {
            return false;
        }

        $row = $this->db->query(
            "INSERT INTO event.block_event
                (check_event_id, entry_id, agent_id, value_protected_usd,
                 tx_hash_attempt, chain_id, occurred_at, tx_hash, log_index)
             VALUES (?, ?, ?, 0, NULL, ?, now(), ?, ?)
             ON CONFLICT (tx_hash, log_index) DO NOTHING
             RETURNING id",
            [
                $checkEventId,
                $entryId,
                '0x' . $agentHex,
                RegistryAbi::CHAIN_ID,
                '\\x' . $txHashHex,
                (int) $decoded['logIndex'],
            ]
        );
        $inserted = $row->fetch(\PDO::FETCH_ASSOC) !== false;

        if ($inserted) {
            $this->db->query(
                "INSERT INTO antibody.publisher (address, successful_blocks, total_earned_usdc, last_active_at)
                 VALUES (?, 1, ?, now())
                 ON CONFLICT (address) DO UPDATE SET
                    successful_blocks = antibody.publisher.successful_blocks + 1,
                    total_earned_usdc = antibody.publisher.total_earned_usdc + EXCLUDED.total_earned_usdc,
                    last_active_at    = GREATEST(antibody.publisher.last_active_at, EXCLUDED.last_active_at)",
                ['\\x' . $publisherHex, $publisherReward]
            );

            $this->db->query(
                "INSERT INTO event.activity (event_type, entry_id, payload, actor, occurred_at)
                 VALUES ('protected'::event.activity_type, ?, jsonb_build_object('agent', ?::text), ?, now())",
                [$entryId, '0x' . $agentHex, '0x' . $agentHex]
            );
        }

        return $inserted;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }

    private static function weiToUsdc(string $value): string
    {
        if (function_exists('bcdiv')) {
            return bcdiv($value, '1000000', 6);
        }
        return number_format(((float) $value) / 1_000_000, 6, '.', '');
    }
}
