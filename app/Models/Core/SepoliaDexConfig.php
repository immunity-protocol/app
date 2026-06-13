<?php

declare(strict_types=1);

namespace App\Models\Core;

/**
 * DEX config block consumed by the /dex demo page.
 *
 * Pulls the Mirror + Hook addresses, the protected and unprotected pool
 * keys, and the V4 PoolManager / PositionManager / Router addresses needed
 * for client-side swap calls. Defaults are pinned to the live Base Sepolia
 * (84532) deployment from `immunity-contracts-mirror@continuity` (see
 * contracts-v1-plan/DEPLOYED-base-sepolia.md); override any field via env.
 */
final class SepoliaDexConfig
{
    public function __construct(
        public readonly int $chainId,
        public readonly string $rpcUrl,
        public readonly string $probeRpcUrl,
        public readonly string $blockExplorerUrl,
        public readonly string $mirrorAddress,
        public readonly string $hookAddress,
        public readonly string $poolManagerAddress,
        public readonly string $positionManagerAddress,
        public readonly string $swapRouterAddress,
        public readonly string $quoterAddress,
        public readonly string $tokenA,
        public readonly string $tokenB,
        public readonly string $currency0,
        public readonly string $currency1,
        public readonly int $fee,
        public readonly int $tickSpacing,
        public readonly string $protectedPoolId,
        public readonly string $unprotectedPoolId,
        public readonly string $tokenALabel,
        public readonly string $tokenBLabel,
    ) {
    }

    public static function default(): self
    {
        // Base Sepolia (84532) defaults. WETH < USDC numerically, so WETH is
        // currency0 and MockUSDC is currency1 (Uniswap v4 orders by address).
        $weth = getenv('BASE_DEX_WETH')  ?: '0x4200000000000000000000000000000000000006';
        $usdc = getenv('BASE_DEX_USDC')  ?: '0xe697EF7724453F239D8c0EB9295D87C344D9CE60';

        return new self(
            chainId:                (int) (getenv('BASE_CHAIN_ID') ?: 84532),
            rpcUrl:                 getenv('BASE_SEPOLIA_RPC_URL')   ?: 'https://sepolia.base.org',
            // Archive-enabled RPC used only by DexBlockIngestor::probeRevertData
            // when re-running a failed swap at its original block. The default
            // public node is state-pruning, so historical eth_call returns
            // "state not available" and the hook's TokenBlocked / SenderBlocked /
            // OriginBlocked never surface. Point this at a full Base Sepolia
            // archive node (e.g. an Alchemy / drpc free-tier endpoint) in prod.
            probeRpcUrl:            getenv('BASE_PROBE_RPC_URL')     ?: 'https://sepolia.base.org',
            blockExplorerUrl:       getenv('BASE_BLOCK_EXPLORER')    ?: 'https://sepolia.basescan.org',
            mirrorAddress:          getenv('BASE_MIRROR_ADDRESS')    ?: '0x6C65b6588B6FE02D33fDc090E6F5432e3Df62fba',
            hookAddress:            getenv('BASE_HOOK_ADDRESS')      ?: '0x42C8471FBDb1cC4B3DE774a890F98801f5F10080',
            poolManagerAddress:     getenv('BASE_POOL_MANAGER')      ?: '0x05E73354cFDd6745C338b50BcFDfA3Aa6fA03408',
            positionManagerAddress: getenv('BASE_POSITION_MANAGER')  ?: '0x4B2C77d209D3405F41a037Ec6c77F7F5b8e2ca80',
            // PoolSwapTest is the canonical Base Sepolia swap-test router and is
            // on the hook's never-block allowlist; the /dex client + the
            // DexBlockIngestor route through it. UniversalRouter
            // (0x492E6456…) is the alternative allowlisted router.
            swapRouterAddress:      getenv('BASE_SWAP_ROUTER')       ?: '0x8B5bcC363ddE2614281aD875bad385E0A785D3B9',
            quoterAddress:          getenv('BASE_QUOTER')            ?: '0x0000000000000000000000000000000000000000',
            tokenA:                 getenv('BASE_DEX_TOKEN_A')       ?: $usdc,
            tokenB:                 getenv('BASE_DEX_TOKEN_B')       ?: $weth,
            currency0:              getenv('BASE_DEX_CURRENCY0')     ?: $weth,
            currency1:              getenv('BASE_DEX_CURRENCY1')     ?: $usdc,
            fee:                    (int) (getenv('BASE_DEX_FEE')          ?: 3000),
            tickSpacing:            (int) (getenv('BASE_DEX_TICK_SPACING') ?: 60),
            protectedPoolId:        getenv('BASE_PROTECTED_POOL_ID')   ?: '0x0000000000000000000000000000000000000000000000000000000000000000',
            unprotectedPoolId:      getenv('BASE_UNPROTECTED_POOL_ID') ?: '0x0000000000000000000000000000000000000000000000000000000000000000',
            tokenALabel:            getenv('BASE_DEX_TOKEN_A_LABEL') ?: 'USDC-T',
            tokenBLabel:            getenv('BASE_DEX_TOKEN_B_LABEL') ?: 'ETH-T',
        );
    }

    /**
     * Whether the unprotected pool has been seeded yet. The /dex page falls
     * back to a "coming soon" hint while this is false.
     */
    public function hasUnprotectedPool(): bool
    {
        return $this->unprotectedPoolId !== ''
            && $this->unprotectedPoolId !== '0x0000000000000000000000000000000000000000000000000000000000000000';
    }
}
