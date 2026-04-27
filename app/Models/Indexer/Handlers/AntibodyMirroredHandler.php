<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * AntibodyMirrored(indexed bytes32 keccakId, indexed address publisher,
 *                  indexed uint8 abType)
 *
 * Emitted by Mirror.mirrorAntibody / mirrorAddressAntibody on the destination
 * chain after the relayer's tx is mined. The indexer:
 *   1. INSERTs into antibody.mirror (idempotent on mirror_tx_hash+log_index).
 *   2. Flips the matching mirror.pending_jobs row to 'confirmed', tolerating
 *      any non-failed prior status. The relayer might still be writing 'sent'
 *      when this lands; the permissive WHERE handles that race.
 */
class AntibodyMirroredHandler
{
    public function __construct(
        private readonly Database $db,
        private readonly int $chainId,
        private readonly string $chainName,
    ) {
    }

    /**
     * @param array{event:string,args:array<string,mixed>,blockNumber:int,txHash:string,logIndex:int,address:string} $decoded
     */
    public function handle(array $decoded): bool
    {
        $a = $decoded['args'];

        $keccakIdHex = self::stripHex((string) $a['keccakId']);
        $publisherHex = self::stripHex((string) $a['publisher']);
        $txHashHex = self::stripHex((string) $decoded['txHash']);
        $logIndex = (int) $decoded['logIndex'];

        $keccakBytea = '\\x' . $keccakIdHex;
        $txBytea = '\\x' . $txHashHex;
        $publisherBytea = '\\x' . $publisherHex;

        // Resolve entry_id from antibody.entry. If the entry isn't present yet
        // (rare cross-chain race during backfill), skip the mirror row but
        // still close the pending job so the relayer doesn't retry.
        $entry = $this->db->selectOne(
            "SELECT id FROM antibody.entry WHERE keccak_id = ?",
            [$keccakBytea]
        );

        if ($entry !== null) {
            $this->db->query(
                "INSERT INTO antibody.mirror
                    (entry_id, chain_id, chain_name, mirror_tx_hash, log_index, status, relayer_address)
                 VALUES (?, ?, ?, ?, ?, 'active'::antibody.mirror_status, ?)
                 ON CONFLICT (mirror_tx_hash, log_index) DO NOTHING",
                [(int) $entry->id, $this->chainId, $this->chainName, $txBytea, $logIndex, $publisherBytea]
            );
        }

        $upd = $this->db->query(
            "UPDATE mirror.pending_jobs
                SET status       = 'confirmed',
                    tx_hash      = COALESCE(tx_hash, ?),
                    confirmed_at = now()
              WHERE keccak_id = ?
                AND target_chain_id = ?
                AND status IN ('pending'::mirror.job_status, 'in_flight'::mirror.job_status, 'sent'::mirror.job_status)
                AND job_type IN ('mirror'::mirror.job_type, 'mirror_address'::mirror.job_type)",
            [$txBytea, $keccakBytea, $this->chainId]
        );

        return $entry !== null || $upd->rowCount() > 0;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        return strtolower($hex);
    }
}
