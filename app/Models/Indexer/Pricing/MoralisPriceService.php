<?php

declare(strict_types=1);

namespace App\Models\Indexer\Pricing;

use App\Models\Indexer\Brokers\TokenPriceCacheBroker;
use Moralis\MoralisService;
use Throwable;

/**
 * Computes a USD value for a `(tokenAddress, chainId, tokenAmount)` triple
 * using Moralis as the price oracle, with a Postgres-backed cache.
 *
 * Returns `null` when no value can be derived (unsupported chain, Moralis
 * unreachable, unknown token). The handler should mark the row's
 * `pricing_failed = true` on null so the retry worker can backfill later.
 *
 * Stale cache rows are NEVER deleted; if Moralis is down, `priceUsd` falls
 * back to the most recent cached value rather than returning null.
 */
final class MoralisPriceService
{
    public const TTL_SECONDS = 300; // 5 minutes
    private const NATIVE = '0x0000000000000000000000000000000000000000';

    /**
     * Map of evm chain id => [moralis chain identifier, wrapped-native address].
     *
     * Native-token transactions arrive with `tokenAddress = 0x0`; we look up
     * the wrapped equivalent (WETH, WMATIC, etc.) on the same chain because
     * Moralis prices ERC20s, not native coins directly.
     */
    private const CHAINS = [
        1        => ['eth',      '0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2'], // WETH
        11155111 => ['sepolia',  '0x7b79995e5f793a07bc00c21412e50ecae098e7f9'], // WETH (Sepolia)
        8453     => ['base',     '0x4200000000000000000000000000000000000006'], // WETH (Base)
        42161    => ['arbitrum', '0x82af49447d8a07e3bd95bd0d56f35241523fbab1'], // WETH (Arbitrum)
        10       => ['optimism', '0x4200000000000000000000000000000000000006'], // WETH (Optimism)
        137      => ['polygon',  '0x0d500b1d8e8ef31e21c99d1db9a6444d3adf1270'], // WMATIC
        56       => ['bsc',      '0xbb4cdb9cbd36b01bd1cbaebf2de08d9173bc095c'], // WBNB
        43114    => ['avalanche','0xb31f66aa3c1e785363f0875a1b74e27b85fd66c7'], // WAVAX
    ];

    public function __construct(
        private readonly MoralisService $moralis,
        private readonly TokenPriceCacheBroker $cache,
    ) {
    }

    /**
     * @param string $tokenAmount integer-string in the token's native decimals (no scaling)
     * @param ?int   $hintDecimals override the cached/fetched decimals (e.g. when the
     *                              caller already knows USDC=6, WETH=18)
     */
    public function priceUsd(
        string $tokenAddress,
        int $chainId,
        string $tokenAmount,
        ?int $hintDecimals = null,
    ): ?string {
        if ($tokenAmount === '' || $tokenAmount === '0') {
            return '0';
        }

        $resolved = $this->resolveAddress($tokenAddress, $chainId);
        if ($resolved === null) {
            return null; // unsupported chain (e.g. 0G Galileo)
        }
        [$moralisChain, $lookupAddress] = $resolved;

        $cached = $this->cache->find($lookupAddress, $chainId);
        if ($cached !== null && self::isFresh((string) $cached->fetched_at)) {
            return $this->compute(
                $tokenAmount,
                (string) $cached->usd_price,
                $hintDecimals ?? (int) $cached->decimals,
            );
        }

        try {
            $token = $this->moralis->fetchToken($lookupAddress, $moralisChain);
            $this->cache->upsert(
                $lookupAddress,
                $chainId,
                $token->usdPrice,
                $token->tokenDecimals,
                $token->tokenSymbol === '' ? null : $token->tokenSymbol,
            );
            return $this->compute(
                $tokenAmount,
                (string) $token->usdPrice,
                $hintDecimals ?? $token->tokenDecimals,
            );
        } catch (Throwable $e) {
            // Fall back to stale cache if available — better than NULL when
            // Moralis is rate-limiting us briefly.
            if ($cached !== null) {
                return $this->compute(
                    $tokenAmount,
                    (string) $cached->usd_price,
                    $hintDecimals ?? (int) $cached->decimals,
                );
            }
            return null;
        }
    }

    /**
     * @return array{0:string,1:string}|null [moralisChain, lookupAddress]
     */
    private function resolveAddress(string $tokenAddress, int $chainId): ?array
    {
        if (!isset(self::CHAINS[$chainId])) {
            return null;
        }
        [$moralisChain, $wrappedNative] = self::CHAINS[$chainId];
        $lower = strtolower($tokenAddress);
        $lookup = ($lower === self::NATIVE) ? $wrappedNative : $lower;
        return [$moralisChain, $lookup];
    }

    /**
     * Compute `tokenAmount * usdPrice / 10^decimals` with bcmath for precision.
     * Returns a decimal string suitable for `numeric(20, 6)` insertion.
     */
    private function compute(string $tokenAmount, string $usdPrice, int $decimals): string
    {
        if (function_exists('bcmul') && function_exists('bcdiv') && function_exists('bcpow')) {
            $divisor = bcpow('10', (string) $decimals);
            $tokens  = bcdiv($tokenAmount, $divisor, 18);
            $usd     = bcmul($tokens, $usdPrice, 6);
            return $usd;
        }
        $value = ((float) $tokenAmount / (10 ** $decimals)) * (float) $usdPrice;
        return number_format($value, 6, '.', '');
    }

    private static function isFresh(string $fetchedAt): bool
    {
        $ts = strtotime($fetchedAt);
        if ($ts === false) {
            return false;
        }
        return (time() - $ts) < self::TTL_SECONDS;
    }
}
