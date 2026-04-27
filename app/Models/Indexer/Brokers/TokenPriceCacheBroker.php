<?php

declare(strict_types=1);

namespace App\Models\Indexer\Brokers;

use App\Models\Core\Broker;
use stdClass;

/**
 * Postgres-backed cache for Moralis token price lookups. Mirrors the ENS
 * resolution caching pattern (cache-then-refresh).
 *
 * Stale rows are NOT deleted on refresh — they remain a usable fallback if
 * Moralis is temporarily unreachable.
 */
class TokenPriceCacheBroker extends Broker
{
    /**
     * Look up a cached price entry by (lowercase 0x-prefixed token address, chain id).
     * Returns null if no cache entry exists. Caller decides whether the
     * `fetched_at` timestamp is fresh enough to trust.
     */
    public function find(string $tokenAddress, int $chainId): ?stdClass
    {
        $hex = self::toBytea($tokenAddress);
        return $this->selectOne(
            "SELECT token_address, chain_id, usd_price, decimals, symbol, fetched_at
               FROM indexer.token_price_cache
              WHERE token_address = ? AND chain_id = ?",
            [$hex, $chainId]
        );
    }

    /**
     * Insert or update a cache entry. Uses a single upsert so the
     * fetched_at timestamp always advances on a successful refresh.
     */
    public function upsert(
        string $tokenAddress,
        int $chainId,
        float $usdPrice,
        int $decimals,
        ?string $symbol,
    ): void {
        $hex = self::toBytea($tokenAddress);
        $this->db->query(
            "INSERT INTO indexer.token_price_cache
                (token_address, chain_id, usd_price, decimals, symbol, fetched_at)
             VALUES (?, ?, ?, ?, ?, now())
             ON CONFLICT (token_address, chain_id) DO UPDATE SET
                usd_price  = EXCLUDED.usd_price,
                decimals   = EXCLUDED.decimals,
                symbol     = EXCLUDED.symbol,
                fetched_at = EXCLUDED.fetched_at",
            [$hex, $chainId, (string) $usdPrice, $decimals, $symbol]
        );
    }

    /**
     * Convert a 0x-prefixed hex address into a Postgres bytea literal.
     */
    private static function toBytea(string $hex): string
    {
        $clean = strtolower($hex);
        if (str_starts_with($clean, '0x')) {
            $clean = substr($clean, 2);
        }
        return '\\x' . $clean;
    }
}
