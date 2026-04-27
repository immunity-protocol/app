<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * StakeReleased(indexed bytes32 keccakId, indexed address publisher,
 *               uint256 amount, uint64 releasedAt)
 *
 * The publisher's stake has unlocked. Move the amount from staked to earned
 * on antibody.publisher (the unlock makes it withdrawable). Append an
 * activity row so the dashboard reflects it.
 */
class StakeReleasedHandler
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
        $amountUsdc = self::weiToUsdc((string) $a['amount']);

        $this->db->query(
            "UPDATE antibody.publisher SET
                total_earned_usdc = total_earned_usdc + ?::numeric(20, 6),
                total_staked_usdc = GREATEST(total_staked_usdc - ?::numeric(20, 6), 0),
                last_active_at    = now()
             WHERE address = ?",
            [$amountUsdc, $amountUsdc, '\\x' . $publisherHex]
        );

        $entryRow = $this->db->query(
            "SELECT id FROM antibody.entry WHERE keccak_id = ? LIMIT 1",
            ['\\x' . $keccakIdHex]
        );
        $entry = $entryRow->fetch(\PDO::FETCH_ASSOC);
        if ($entry !== false) {
            $this->db->query(
                "INSERT INTO event.activity (event_type, entry_id, payload, actor, occurred_at)
                 VALUES ('released'::event.activity_type, ?, jsonb_build_object('amount', ?::text), ?, now())",
                [(int) $entry['id'], $amountUsdc, '0x' . $publisherHex]
            );
        }

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
