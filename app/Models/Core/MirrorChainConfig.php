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
    ) {
    }

    public function relayerPrivateKey(): ?string
    {
        $key = getenv($this->relayerPrivateKeyEnv);
        return ($key === false || $key === '') ? null : $key;
    }
}
