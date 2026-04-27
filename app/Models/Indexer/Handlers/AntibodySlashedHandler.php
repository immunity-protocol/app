<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * AntibodySlashed(indexed bytes32 keccakId, indexed address publisher,
 *                 uint256 stakeAmount)
 *
 * Mark the antibody as slashed and update the publisher's loss counters.
 */
class AntibodySlashedHandler
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
        $publisherHex = strtolower(self::stripHex((string) $a['publisher']));
        $amountUsdc = self::weiToUsdc((string) $a['stakeAmount']);

        $upd = $this->db->query(
            "UPDATE antibody.entry
                SET status = 'slashed'::antibody.entry_status,
                    updated_at = now()
              WHERE keccak_id = ?
                AND status <> 'slashed'::antibody.entry_status
              RETURNING id",
            ['\\x' . $keccakIdHex]
        );
        $entry = $upd->fetch(\PDO::FETCH_ASSOC);
        if ($entry === false) {
            return false;
        }
        $entryId = (int) $entry['id'];

        $this->db->query(
            "UPDATE antibody.publisher SET
                challenges_lost   = challenges_lost + 1,
                total_staked_usdc = GREATEST(total_staked_usdc - ?::numeric(20, 6), 0),
                last_active_at    = now()
              WHERE address = ?",
            [$amountUsdc, '\\x' . $publisherHex]
        );

        $this->db->query(
            "INSERT INTO event.activity (event_type, entry_id, payload, actor, occurred_at)
             VALUES ('challenged'::event.activity_type, ?, jsonb_build_object('amount', ?::text), ?, now())",
            [$entryId, $amountUsdc, '0x' . $publisherHex]
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

    private static function weiToUsdc(string $value): string
    {
        if (function_exists('bcdiv')) {
            return bcdiv($value, '1000000', 6);
        }
        return number_format(((float) $value) / 1_000_000, 6, '.', '');
    }
}
