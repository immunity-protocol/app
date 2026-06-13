<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * Registry.Expired(indexed bytes32 keccakId, indexed address publisher,
 *                  uint256 bondReleased, uint256 escrowClawedBack)
 * Registry.Retired(indexed bytes32 keccakId, indexed address publisher,
 *                  uint256 bondReleased)
 *
 * Both are terminal end-of-life transitions with the bond released; the schema
 * has no distinct 'retired' state, so both map to 'expired'. Bond/escrow
 * mutations ride on the accompanying BondReleased/FeesClawedBack events.
 */
class ExpiredHandler
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{event:string,args:array<string,mixed>,blockNumber:int,txHash:string,logIndex:int,address:string} $decoded
     */
    public function handle(array $decoded): bool
    {
        $keccakIdHex = strtolower(self::stripHex((string) $decoded['args']['keccakId']));

        $row = $this->db->query(
            "UPDATE antibody.entry
                SET status = 'expired'::antibody.entry_status,
                    updated_at = now()
              WHERE keccak_id = ?
                AND status NOT IN ('slashed'::antibody.entry_status, 'expired'::antibody.entry_status)
              RETURNING id",
            ['\\x' . $keccakIdHex]
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
}
