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
        public readonly string $novelVerificationAddress,
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
            deployBlock:               (int) (getenv('BASE_DEPLOY_BLOCK') ?: 42823438),
            registryAddress:           getenv('BASE_REGISTRY_ADDRESS')            ?: '0x15F177B17884B991703300C2dcCBA790Dda33fbC',
            reputationAddress:         getenv('BASE_REPUTATION_ADDRESS')          ?: '0x436510F3382F67bDF1eE4B6c4b6f940Eb492b3c4',
            publisherRegistrarAddress: getenv('BASE_PUBLISHER_REGISTRAR_ADDRESS') ?: '0x55237bE657245A6bf223D4b721A72b8e1D2E8523',
            challengeManagerAddress:   getenv('BASE_CHALLENGE_MANAGER_ADDRESS')   ?: '0xc05ffEA7657d9F2c8879342cDAa2bE0eF238F04d',
            creVerdictReceiverAddress: getenv('BASE_CRE_RECEIVER_ADDRESS')        ?: '0xc4f843aac2C94ce2D349C166d1D8D58cb7049C66',
            novelVerificationAddress:  getenv('BASE_NOVEL_VERIFICATION_ADDRESS')  ?: '0x0f3733f4683029771E7730288339B460Eb377435',
            protectedSetAddress:       getenv('BASE_PROTECTED_SET_ADDRESS')       ?: '0x8b20aE052F9391e7b071A262aDa201F2189A1901',
            l2RegistryAddress:         getenv('BASE_L2_REGISTRY_ADDRESS')         ?: '0xc647c0693ca93D2Ee5681C2eE7AF02d18C76F3B5',
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
            'NovelVerification'  => [$this->novelVerificationAddress,  'NovelVerification.json'],
            'ProtectedSet'       => [$this->protectedSetAddress,       'ProtectedSet.json'],
            'L2Registry'         => [$this->l2RegistryAddress,         'ImmunityL2Registry.json'],
        ];
    }
}
