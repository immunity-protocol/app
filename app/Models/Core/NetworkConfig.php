<?php

declare(strict_types=1);

namespace App\Models\Core;

/**
 * Canonical per-network configuration. Every consumer of network state
 * (Registry/USDC addresses, RPC URLs, storage indexer, AXL hubs, ENS RPC)
 * reads from this object — no hardcoded constants elsewhere in the app.
 *
 * Use the static factory `NetworkConfig::galileo()` to construct from env
 * vars (with hardcoded fallbacks for local dev). Future networks add their
 * own factories rather than scattering new constants.
 */
final class NetworkConfig
{
    public function __construct(
        public readonly string $name,
        public readonly int $chainId,
        public readonly string $rpcUrl,
        public readonly string $registryAddress,
        public readonly string $usdcAddress,
        public readonly string $blockExplorerUrl,
        public readonly string $storageIndexerUrl,
        public readonly string $computeProvider,
        public readonly string $computeModel,
        /** @var string[] */
        public readonly array $axlHubs,
        public readonly string $ensRpcUrl,
    ) {
    }

    /**
     * Build the Galileo testnet config from environment variables, falling back
     * to the current canonical defaults. Override any field by setting the
     * corresponding env var in `.env` or the docker-compose service block.
     */
    public static function galileo(): self
    {
        return new self(
            name:              'galileo-testnet',
            chainId:           (int) (getenv('OG_CHAIN_ID') ?: 16602),
            rpcUrl:            getenv('OG_RPC_URL')           ?: 'https://evmrpc-testnet.0g.ai',
            registryAddress:   getenv('OG_REGISTRY_ADDRESS')  ?: '0x45Ee45Ca358b3fc9B1b245a8f1c1C3128caC8e48',
            usdcAddress:       getenv('OG_USDC_ADDRESS')      ?: '0x2Aee1d140422C62AE23465596801C35f3Ce74F9E',
            blockExplorerUrl:  getenv('OG_BLOCK_EXPLORER')    ?: 'https://chainscan-galileo.0g.ai',
            storageIndexerUrl: getenv('OG_STORAGE_INDEXER')   ?: 'https://indexer-storage-testnet-turbo.0g.ai',
            computeProvider:   getenv('OG_COMPUTE_PROVIDER')  ?: '0xa48f01287233509FD694a22Bf840225062E67836',
            computeModel:      getenv('OG_COMPUTE_MODEL')     ?: 'qwen-2.5-7b-instruct',
            axlHubs:           self::parseHubs(getenv('AXL_HUB_URLS') ?: ''),
            ensRpcUrl:         getenv('ENS_RPC_URL')          ?: 'https://eth.llamarpc.com',
        );
    }

    /** @return string[] */
    private static function parseHubs(string $csv): array
    {
        if ($csv === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $csv));
        return array_values(array_filter($parts, static fn (string $s) => $s !== ''));
    }
}
