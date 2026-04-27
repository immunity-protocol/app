<?php

declare(strict_types=1);

namespace App\Models\Indexer\Workers;

use App\Models\Indexer\Pricing\MoralisPriceService;
use Zephyrus\Data\Database;

/**
 * Periodic retry of failed Moralis price lookups.
 *
 * Whenever a check_event or block_event handler fails to compute a USD value
 * (Moralis down, rate-limited, unsupported chain, unknown token), it writes
 * `pricing_failed = true`. This worker walks those rows in small batches and
 * tries again. On success the value lands and the flag clears.
 *
 * Cadence is set in `Supervisor` (default 60s). Per-tick batch size is small
 * so a flaky Moralis doesn't wedge the indexer.
 */
class PricingRetryWorker
{
    public function __construct(
        private readonly Database $db,
        private readonly MoralisPriceService $pricing,
    ) {
    }

    /**
     * @return array{check_attempted:int,check_priced:int,block_attempted:int,block_priced:int}
     */
    public function tick(int $batchSize = 25): array
    {
        $stats = [
            'check_attempted' => 0,
            'check_priced'    => 0,
            'block_attempted' => 0,
            'block_priced'    => 0,
        ];

        $stats['check_attempted'] = $this->retryCheckEvents($batchSize, $stats['check_priced']);
        $stats['block_attempted'] = $this->retryBlockEvents($batchSize, $stats['block_priced']);

        return $stats;
    }

    private function retryCheckEvents(int $batchSize, int &$priced): int
    {
        $rows = $this->db->query(
            "SELECT id, encode(token_address, 'hex') AS token_hex,
                    token_amount, origin_chain_id
               FROM event.check_event
              WHERE pricing_failed = true
                AND token_address IS NOT NULL
                AND token_amount IS NOT NULL
                AND origin_chain_id IS NOT NULL
              ORDER BY occurred_at DESC
              LIMIT ?",
            [$batchSize]
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $value = $this->pricing->priceUsd(
                '0x' . (string) $r['token_hex'],
                (int) $r['origin_chain_id'],
                (string) $r['token_amount'],
            );
            if ($value === null) {
                continue;
            }
            $this->db->query(
                "UPDATE event.check_event
                    SET value_at_risk_usd = ?, pricing_failed = false
                  WHERE id = ?",
                [$value, (int) $r['id']]
            );
            $priced++;
        }
        return count($rows);
    }

    private function retryBlockEvents(int $batchSize, int &$priced): int
    {
        $rows = $this->db->query(
            "SELECT id, encode(token_address, 'hex') AS token_hex,
                    token_amount, origin_chain_id
               FROM event.block_event
              WHERE pricing_failed = true
                AND token_address IS NOT NULL
                AND token_amount IS NOT NULL
                AND origin_chain_id IS NOT NULL
              ORDER BY occurred_at DESC
              LIMIT ?",
            [$batchSize]
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $value = $this->pricing->priceUsd(
                '0x' . (string) $r['token_hex'],
                (int) $r['origin_chain_id'],
                (string) $r['token_amount'],
            );
            if ($value === null) {
                continue;
            }
            $this->db->query(
                "UPDATE event.block_event
                    SET value_protected_usd = ?, pricing_failed = false
                  WHERE id = ?",
                [$value, (int) $r['id']]
            );
            $priced++;
        }
        return count($rows);
    }
}
