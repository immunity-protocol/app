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
    ) {
    }

    /**
     * Build the Base Sepolia (84532) config from environment variables, falling
     * back to the contracts deployed 2026-06-13 (see
     * contracts-v1-plan/DEPLOYED-base-sepolia.md). Override any field via env.
     */
    public static function baseSepolia(): self
    {
        return new self(
            name:                      'base-sepolia',
            chainId:                   (int) (getenv('BASE_CHAIN_ID') ?: 84532),
            rpcUrl:                    getenv('BASE_SEPOLIA_RPC_URL') ?: 'https://sepolia.base.org',
            blockExplorerUrl:          getenv('BASE_BLOCK_EXPLORER')  ?: 'https://sepolia.basescan.org',
            usdcAddress:               getenv('BASE_USDC_ADDRESS')    ?: '0x26265722fa5d94bB3A3C866124aDdC7b85670b16',
            ensRpcUrl:                 getenv('ENS_RPC_URL')          ?: 'https://eth.llamarpc.com',
            deployBlock:               (int) (getenv('BASE_DEPLOY_BLOCK') ?: 42781168),
            registryAddress:           getenv('BASE_REGISTRY_ADDRESS')            ?: '0xdB155c21D26b917294BF0e2A1E46C9A14361BF44',
            reputationAddress:         getenv('BASE_REPUTATION_ADDRESS')          ?: '0x0e03F6Ca9e97447E2d97aFbFCe4cBF49202e9F25',
            publisherRegistrarAddress: getenv('BASE_PUBLISHER_REGISTRAR_ADDRESS') ?: '0x35F65a08a11f44F73622f51ade1911BC28036faF',
            challengeManagerAddress:   getenv('BASE_CHALLENGE_MANAGER_ADDRESS')   ?: '0xe83525cA155e3f285Cc58f91cB1120338ecb2417',
            creVerdictReceiverAddress: getenv('BASE_CRE_RECEIVER_ADDRESS')        ?: '0xA3FD7E9E7dDc32A25441b93F34AaEcEbE0304485',
            protectedSetAddress:       getenv('BASE_PROTECTED_SET_ADDRESS')       ?: '0xFc9EfB73662ccE25267e9E467c43e812F7C7A4d8',
            l2RegistryAddress:         getenv('BASE_L2_REGISTRY_ADDRESS')         ?: '0xa0A4CE62b6Fa02ed5ddFbb1DE6e56fC559033C06',
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
