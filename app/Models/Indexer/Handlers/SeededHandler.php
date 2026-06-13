<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * Registry.Seeded(indexed bytes32 keccakId, indexed uint32 immSeq)
 *
 * Genesis-seeded antibodies are already inserted ACTIVE by the preceding
 * Published(isSeeded=true). This confirms the seed marking idempotently.
 */
class SeededHandler
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
                SET is_seeded = 1,
                    seed_source = COALESCE(seed_source, 'admin'),
                    status = CASE WHEN status = 'probation'::antibody.entry_status
                                  THEN 'active'::antibody.entry_status ELSE status END,
                    updated_at = now()
              WHERE keccak_id = ?
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
