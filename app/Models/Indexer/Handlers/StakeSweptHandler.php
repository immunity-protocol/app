<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * StakeSwept(indexed address sweeper, uint256 numReleased, uint256 bountyPaid)
 *
 * One row per sweep tx into event.sweep_event.
 */
class StakeSweptHandler
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
        $sweeperHex = strtolower(self::stripHex((string) $a['sweeper']));
        $txHashHex = strtolower(self::stripHex((string) $decoded['txHash']));
        $bountyUsdc = self::weiToUsdc((string) $a['bountyPaid']);

        $row = $this->db->query(
            "INSERT INTO event.sweep_event
                (sweeper, num_released, bounty_paid, occurred_at, block_number, tx_hash, log_index)
             VALUES (?, ?, ?, now(), ?, ?, ?)
             ON CONFLICT (tx_hash, log_index) DO NOTHING
             RETURNING id",
            [
                '\\x' . $sweeperHex,
                (int) $a['numReleased'],
                $bountyUsdc,
                (int) $decoded['blockNumber'],
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

    private static function weiToUsdc(string $value): string
    {
        if (function_exists('bcdiv')) {
            return bcdiv($value, '1000000', 6);
        }
        return number_format(((float) $value) / 1_000_000, 6, '.', '');
    }
}
