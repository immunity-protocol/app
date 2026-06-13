<?php

declare(strict_types=1);

namespace App\Models\Indexer\Chain;

/**
 * Composite ABI for the Base Sepolia contract suite. Maps each deployed
 * address to its per-contract ABI so a single EventPoller can watch all seven
 * contracts on one cursor (one eth_getLogs over the address array) while the
 * decoder still resolves events per-contract.
 *
 * The contract name attached to each ABI becomes the handler-dispatch prefix
 * ("Registry.Published", "Reputation.Matured", …).
 */
final class BaseChainAbi implements ContractRegistry
{
    /** @var array<string, ContractAbi> lowercase 0x address -> ABI */
    private array $byAddress = [];

    /**
     * @param array<string, ContractAbi> $byAddress lowercase 0x address -> ABI
     */
    public function __construct(array $byAddress)
    {
        foreach ($byAddress as $address => $abi) {
            $this->byAddress[strtolower($address)] = $abi;
        }
    }

    /**
     * Build the suite from a name => [address, abiFileName] map, loading each
     * ABI from app/Models/Indexer/Chain/abi/.
     *
     * @param array<string, array{0:string,1:string}> $contracts name => [address, abiFile]
     */
    public static function fromContracts(array $contracts, ?string $abiDir = null): self
    {
        $abiDir ??= __DIR__ . '/abi';
        $byAddress = [];
        foreach ($contracts as $name => [$address, $abiFile]) {
            $byAddress[strtolower($address)] = new ContractAbi($name, $abiDir . '/' . $abiFile);
        }
        return new self($byAddress);
    }

    public function contractAt(string $address): ?ContractAbi
    {
        return $this->byAddress[strtolower($address)] ?? null;
    }

    public function addresses(): array
    {
        return array_keys($this->byAddress);
    }

    /**
     * Flat topic0 lookup across all contracts (first match). The decoder
     * prefers the address-aware path; this exists only to satisfy EventAbi.
     */
    public function eventByTopic(string $topic0): ?array
    {
        foreach ($this->byAddress as $abi) {
            $item = $abi->eventByTopic($topic0);
            if ($item !== null) {
                return $item;
            }
        }
        return null;
    }
}
