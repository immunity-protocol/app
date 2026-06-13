<?php

declare(strict_types=1);

namespace App\Models\Indexer\Chain;

/**
 * An address-aware ABI source: maps each watched on-chain address to its own
 * per-contract ABI. The EventDecoder uses this to resolve which contract
 * emitted a log before decoding, so that same-named events on different
 * contracts (e.g. Registry.Matured vs Reputation.Matured) and identical
 * signatures (e.g. Registry/ChallengeManager TreasuryWithdrawn) never collide.
 */
interface ContractRegistry extends EventAbi
{
    /**
     * @param string $address lowercase or checksummed 0x address
     * @return ContractAbi|null the per-contract ABI, or null if not watched
     */
    public function contractAt(string $address): ?ContractAbi;

    /**
     * @return string[] all watched contract addresses (lowercase 0x)
     */
    public function addresses(): array;
}
