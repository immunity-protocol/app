<?php

declare(strict_types=1);

namespace App\Models\Core;

/**
 * Canonical per-network configuration. Every consumer of network state
 * (contract addresses, RPC URL, explorer, USDC, ENS RPC, deploy block) reads
 * from this object — no hardcoded constants elsewhere in the app.
 *
 * Use the static factory `NetworkConfig::baseSepolia()` to construct from env
 * vars (with the deployed Base Sepolia defaults for local dev). Future networks
 * add their own factories rather than scattering new constants.
 */
final class NetworkConfig
{
    public function __construct(
        public readonly string $name,
        public readonly int $chainId,
        public readonly string $rpcUrl,
        public readonly string $blockExplorerUrl,
        public readonly string $usdcAddress,
        public readonly string $ensRpcUrl,
        public readonly int $deployBlock,
        public readonly string $registryAddress,
        public readonly string $reputationAddress,
        public readonly string $publisherRegistrarAddress,
        public readonly string $challengeManagerAddress,
        public readonly string $creVerdictReceiverAddress,
        public readonly string $protectedSetAddress,
        public readonly string $l2RegistryAddress,
        public readonly int $corroborationK = 3,
    ) {
    }

    /**
     * Build the Base Sepolia (84532) config from environment variables, falling
     * back to the hardened core redeployed + seeded 2026-06-13 (see
     * contracts-v1-plan/DEPLOYED-base-sepolia.md). Override any field via env.
     */
    public static function baseSepolia(): self
    {
        return new self(
            name:                      'base-sepolia',
            chainId:                   (int) (getenv('BASE_CHAIN_ID') ?: 84532),
            rpcUrl:                    getenv('BASE_SEPOLIA_RPC_URL') ?: 'https://sepolia.base.org',
            blockExplorerUrl:          getenv('BASE_BLOCK_EXPLORER')  ?: 'https://sepolia.basescan.org',
            usdcAddress:               getenv('BASE_USDC_ADDRESS')    ?: '0xe697EF7724453F239D8c0EB9295D87C344D9CE60',
            ensRpcUrl:                 getenv('ENS_RPC_URL')          ?: 'https://eth.llamarpc.com',
            deployBlock:               (int) (getenv('BASE_DEPLOY_BLOCK') ?: 42796000),
            registryAddress:           getenv('BASE_REGISTRY_ADDRESS')            ?: '0x9bD765E191e186679252467Ebbc1D389a59E04B8',
            reputationAddress:         getenv('BASE_REPUTATION_ADDRESS')          ?: '0x828666a9E2887F8dD03E61b0D9546C32CaDB52d3',
            publisherRegistrarAddress: getenv('BASE_PUBLISHER_REGISTRAR_ADDRESS') ?: '0x762CF28bE7502CC99B6286076e9b4Fb71EE84002',
            challengeManagerAddress:   getenv('BASE_CHALLENGE_MANAGER_ADDRESS')   ?: '0xc71c354fFf57652A64b214F654E1A68c7f3cef79',
            creVerdictReceiverAddress: getenv('BASE_CRE_RECEIVER_ADDRESS')        ?: '0x02ED0a8b0e6b98C1C05CE125157566313cEe4834',
            protectedSetAddress:       getenv('BASE_PROTECTED_SET_ADDRESS')       ?: '0x95faC80e27419619A9108C53573bf9A77967397A',
            l2RegistryAddress:         getenv('BASE_L2_REGISTRY_ADDRESS')         ?: '0xded674AAbCe67B2cFe724c8c50c928830468E0cC',
            corroborationK:            (int) (getenv('BASE_CORROBORATION_K') ?: 3),
        );
    }

    /**
     * Map of contract name => [address, abiFileName] for the full Base suite,
     * consumed by BaseChainAbi::fromContracts() and the indexer wiring. The name
     * is the handler-dispatch prefix (e.g. "Registry.Published").
     *
     * @return array<string, array{0:string,1:string}>
     */
    public function contracts(): array
    {
        return [
            'Registry'           => [$this->registryAddress,           'ImmunityRegistry.json'],
            'Reputation'         => [$this->reputationAddress,         'Reputation.json'],
            'PublisherRegistrar' => [$this->publisherRegistrarAddress, 'PublisherRegistrar.json'],
            'ChallengeManager'   => [$this->challengeManagerAddress,   'ChallengeManager.json'],
            'CREVerdictReceiver' => [$this->creVerdictReceiverAddress, 'CREVerdictReceiver.json'],
            'ProtectedSet'       => [$this->protectedSetAddress,       'ProtectedSet.json'],
            'L2Registry'         => [$this->l2RegistryAddress,         'ImmunityL2Registry.json'],
        ];
    }
}
