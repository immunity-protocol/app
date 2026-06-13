<?php

declare(strict_types=1);

namespace App\Models\Core;

/**
 * DEX config block consumed by the /dex demo page.
 *
 * Pulls the Mirror + Hook addresses, the protected and unprotected pool
 * keys, and the V4 PoolManager / PositionManager / Router addresses needed
 * for client-side swap calls. Defaults are pinned to the live ETHEREUM Sepolia
 * (11155111) deployment from `immunity-contracts-mirror@continuity` — the
 * Mirror is the cross-chain target of the Base Registry, so the v4 hook that
 * reads it (and the two seeded demo pools) live on Ethereum Sepolia. Override
 * any field via env. (Env keys keep the historical `BASE_*` names for back-compat.)
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
        // Ethereum Sepolia (11155111) defaults — the live demo deployment.
        // Two structurally-identical v4 pools over the same INT-A / INT-B test
        // tokens; the protected pool carries the Immunity hook, the unprotected
        // one does not. INT-B (0x1EE9…) < INT-A (0xC6dF…) numerically, so INT-B
        // is currency0 and INT-A is currency1 (v4 orders by address). INT-A is
        // flagged in the Mirror, so every swap on the protected pool reverts.
        $intA = getenv('BASE_DEX_TOKEN_A') ?: '0xC6dFD5fCb9EB7D210c5D3C5bAB1681094Adfa281';
        $intB = getenv('BASE_DEX_TOKEN_B') ?: '0x1EE9A246758d97E583726F0f296dEea3d37C4eb8';

        return new self(
            chainId:                (int) (getenv('BASE_CHAIN_ID') ?: 11155111),
            rpcUrl:                 getenv('SEPOLIA_RPC_URL')        ?: 'https://eth-sepolia.g.alchemy.com/v2/C5BdobTzYALqWfs3wDc-I',
            // Archive-enabled RPC used only by DexBlockIngestor::probeRevertData
            // when re-running a failed swap at its original block. The default
            // public node is state-pruning, so historical eth_call returns
            // "state not available" and the hook's TokenBlocked / SenderBlocked /
            // OriginBlocked never surface. Alchemy's Sepolia endpoint serves the
            // recent-block archive the probe needs.
            probeRpcUrl:            getenv('BASE_PROBE_RPC_URL')     ?: (getenv('SEPOLIA_RPC_URL') ?: 'https://eth-sepolia.g.alchemy.com/v2/C5BdobTzYALqWfs3wDc-I'),
            blockExplorerUrl:       getenv('BASE_BLOCK_EXPLORER')    ?: 'https://sepolia.etherscan.io',
            mirrorAddress:          getenv('BASE_MIRROR_ADDRESS')    ?: '0x6C65b6588B6FE02D33fDc090E6F5432e3Df62fba',
            hookAddress:            getenv('BASE_HOOK_ADDRESS')      ?: '0x49EcBd9c5913A978C6C00D41702A2BC9EF828080',
            poolManagerAddress:     getenv('BASE_POOL_MANAGER')      ?: '0xE03A1074c86CFeDd5C142C4F04F1a1536e203543',
            positionManagerAddress: getenv('BASE_POSITION_MANAGER')  ?: '0x429ba70129df741B2Ca2a85BC3A2a3328e5c09b4',
            // hookmate's V4SwapRouter — canonical on Sepolia. Not antibody-flagged,
            // so the hook lets it through; the /dex client + DexBlockIngestor
            // route swaps through it.
            swapRouterAddress:      getenv('BASE_SWAP_ROUTER')       ?: '0xf13D190e9117920c703d79B5F33732e10049b115',
            quoterAddress:          getenv('BASE_QUOTER')            ?: '0x0000000000000000000000000000000000000000',
            tokenA:                 $intA,
            tokenB:                 $intB,
            currency0:              getenv('BASE_DEX_CURRENCY0')     ?: $intB,
            currency1:              getenv('BASE_DEX_CURRENCY1')     ?: $intA,
            fee:                    (int) (getenv('BASE_DEX_FEE')          ?: 3000),
            tickSpacing:            (int) (getenv('BASE_DEX_TICK_SPACING') ?: 60),
            protectedPoolId:        getenv('BASE_PROTECTED_POOL_ID')   ?: '0x3da31e9c10d509506bc8bcc50867b6da38ea6820fd588c6736021210327a2cc9',
            unprotectedPoolId:      getenv('BASE_UNPROTECTED_POOL_ID') ?: '0xe706a1b80032a97f7e2c6fbc56a3f51ef23f312393f85b7cb81425f174f386ea',
            tokenALabel:            getenv('BASE_DEX_TOKEN_A_LABEL') ?: 'INT-A',
            tokenBLabel:            getenv('BASE_DEX_TOKEN_B_LABEL') ?: 'INT-B',
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
