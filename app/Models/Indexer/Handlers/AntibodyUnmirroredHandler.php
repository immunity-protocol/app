<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * AntibodyUnmirrored(indexed bytes32 keccakId)
 *
 * Emitted by Mirror.unmirrorAntibody on the destination chain. The indexer:
 *   1. Marks the corresponding antibody.mirror row as 'removed'.
 *   2. Flips the matching mirror.pending_jobs unmirror row to 'confirmed'.
 *
 * Idempotent: replays just re-execute the same UPDATEs (no-op on rows already
 * removed / confirmed).
 */
class AntibodyUnmirroredHandler
{
    public function __construct(
        private readonly Database $db,
        private readonly int $chainId,
    ) {
    }

    /**
     * @param array{event:string,args:array<string,mixed>,blockNumber:int,txHash:string,logIndex:int,address:string} $decoded
     */
    public function handle(array $decoded): bool
    {
        $a = $decoded['args'];
        $keccakIdHex = self::stripHex((string) $a['keccakId']);
        $txHashHex = self::stripHex((string) $decoded['txHash']);
        $keccakBytea = '\\x' . $keccakIdHex;
        $txBytea = '\\x' . $txHashHex;

        $entry = $this->db->selectOne(
            "SELECT id FROM antibody.entry WHERE keccak_id = ?",
            [$keccakBytea]
        );

        $changed = false;
        if ($entry !== null) {
            $upd = $this->db->query(
                "UPDATE antibody.mirror
                    SET status = 'removed'::antibody.mirror_status
                  WHERE entry_id = ?
                    AND chain_id = ?
                    AND status   = 'active'::antibody.mirror_status",
                [(int) $entry->id, $this->chainId]
            );
            $changed = $upd->rowCount() > 0;
        }

        $jobUpd = $this->db->query(
            "UPDATE mirror.pending_jobs
                SET status       = 'confirmed',
                    tx_hash      = COALESCE(tx_hash, ?),
                    confirmed_at = now()
              WHERE keccak_id = ?
                AND target_chain_id = ?
                AND status IN ('pending'::mirror.job_status, 'in_flight'::mirror.job_status, 'sent'::mirror.job_status)
                AND job_type = 'unmirror'::mirror.job_type",
            [$txBytea, $keccakBytea, $this->chainId]
        );

        return $changed || $jobUpd->rowCount() > 0;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        return strtolower($hex);
    }
}
