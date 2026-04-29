<?php

declare(strict_types=1);

namespace App\Models\Core;

/**
 * Sepolia config block consumed by the /dex demo page.
 *
 * Pulls the Mirror + Hook addresses, the protected and unprotected pool
 * keys, and the V4 PoolManager / PositionManager / Router addresses needed
 * for client-side swap calls. Defaults are pinned to the live Sepolia
 * deployment from `immunity-contracts-mirror`; override any field via env.
 */
final class SepoliaDexConfig
{
    public function __construct(
        public readonly int $chainId,
        public readonly string $rpcUrl,
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
        return new self(
            chainId:                11155111,
            rpcUrl:                 getenv('SEPOLIA_RPC_URL')         ?: 'https://ethereum-sepolia-rpc.publicnode.com',
            blockExplorerUrl:       getenv('SEPOLIA_BLOCK_EXPLORER')  ?: 'https://sepolia.etherscan.io',
            mirrorAddress:          getenv('SEPOLIA_MIRROR_ADDRESS')  ?: '0x1be1Ec2F7E2230f9bB1Aa3d5589bB58F8DfD52c7',
            hookAddress:            getenv('SEPOLIA_HOOK_ADDRESS')    ?: '0xd3335F3d69e97C314350EDA63fB5Ba0163Dd0080',
            poolManagerAddress:     getenv('SEPOLIA_POOL_MANAGER')    ?: '0xE03A1074c86CFeDd5C142C4F04F1a1536e203543',
            positionManagerAddress: getenv('SEPOLIA_POSITION_MANAGER') ?: '0x429ba70129df741B2Ca2a85BC3A2a3328e5c09b4',
            swapRouterAddress:      getenv('SEPOLIA_SWAP_ROUTER')     ?: '0xf13D190e9117920c703d79B5F33732e10049b115',
            quoterAddress:          getenv('SEPOLIA_QUOTER')          ?: '0x61b3f2011a92d183c7dbadbda940a7555ccf9227',
            tokenA:                 getenv('SEPOLIA_DEX_TOKEN_A')     ?: '0xF4F4d4f459b339c7234511547880E101073DCbCd',
            tokenB:                 getenv('SEPOLIA_DEX_TOKEN_B')     ?: '0x479504943734d01548B2975227Bb6BfCF725c222',
            currency0:              getenv('SEPOLIA_DEX_CURRENCY0')   ?: '0x479504943734d01548B2975227Bb6BfCF725c222',
            currency1:              getenv('SEPOLIA_DEX_CURRENCY1')   ?: '0xF4F4d4f459b339c7234511547880E101073DCbCd',
            fee:                    (int) (getenv('SEPOLIA_DEX_FEE')          ?: 3000),
            tickSpacing:            (int) (getenv('SEPOLIA_DEX_TICK_SPACING') ?: 60),
            protectedPoolId:        getenv('SEPOLIA_PROTECTED_POOL_ID')   ?: '0x180b6f589c55732f9bc670360dfcb418e91798e2a6b3ee77aa5d6a5d172fd68e',
            unprotectedPoolId:      getenv('SEPOLIA_UNPROTECTED_POOL_ID') ?: '0x0000000000000000000000000000000000000000000000000000000000000000',
            tokenALabel:            getenv('SEPOLIA_DEX_TOKEN_A_LABEL') ?: 'USDC-T',
            tokenBLabel:            getenv('SEPOLIA_DEX_TOKEN_B_LABEL') ?: 'ETH-T',
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
