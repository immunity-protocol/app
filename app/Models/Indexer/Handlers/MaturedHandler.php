<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * Registry.Matured(indexed bytes32 keccakId, indexed address publisher,
 *                  uint256 releasedFees, uint64 maturedAt)
 *
 * Promotes a probation antibody to ACTIVE and records the maturation time. The
 * escrow release itself is handled by the accompanying FeesReleased event (see
 * BondLedgerHandler) so this handler owns only the entry state transition.
 */
class MaturedHandler
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
        $maturedAt = (int) $a['maturedAt'];

        $row = $this->db->query(
            "UPDATE antibody.entry
                SET status = 'active'::antibody.entry_status,
                    matured_at = to_timestamp(?),
                    updated_at = now()
              WHERE keccak_id = ?
                AND status = 'probation'::antibody.entry_status
              RETURNING id",
            [$maturedAt, '\\x' . $keccakIdHex]
        );
        $entry = $row->fetch(\PDO::FETCH_ASSOC);
        if ($entry === false) {
            return false;
        }

        $this->db->query(
            "INSERT INTO event.activity (event_type, entry_id, payload, actor, occurred_at)
             SELECT 'released'::event.activity_type, ?, jsonb_build_object('imm_id', imm_id), '0x' || encode(publisher, 'hex'), now()
               FROM antibody.entry WHERE id = ?",
            [(int) $entry['id'], (int) $entry['id']]
        );

        return true;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }
}
