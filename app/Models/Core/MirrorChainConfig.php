<?php

declare(strict_types=1);

namespace App\Models\Core;

/**
 * One execution chain that hosts a Mirror contract. Built from
 * config/mirror-network.json by MirrorNetworkRegistry.
 */
final class MirrorChainConfig
{
    public function __construct(
        public readonly int $chainId,
        public readonly string $name,
        public readonly string $rpcUrl,
        public readonly string $mirrorAddress,
        public readonly int $deployBlock,
        public readonly string $relayerPrivateKeyEnv,
        // Per-chain event-poll interval in milliseconds. Overrides the
        // global INDEXER_POLL_INTERVAL_MS for this chain. Use it to crank
        // down RPC traffic on slow chains (Sepolia mirror events fire at
        // human pace; once-per-hour polling is fine).
        public readonly ?int $pollIntervalMs = null,
    ) {
    }

    public function relayerPrivateKey(): ?string
    {
        $key = getenv($this->relayerPrivateKeyEnv);
        return ($key === false || $key === '') ? null : $key;
    }
}
