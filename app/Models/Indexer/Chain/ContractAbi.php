<?php

declare(strict_types=1);

namespace App\Models\Indexer\Chain;

use kornrunner\Keccak;
use RuntimeException;

/**
 * Loads a single compiled-contract ABI (Hardhat artifact JSON) and exposes
 * event lookups by name and by topic0 (keccak256 of the canonical signature).
 * Pure-data class, no side effects.
 *
 * Generalizes the old RegistryAbi so the Base suite can load one of these per
 * deployed contract (Registry, Reputation, PublisherRegistrar, …) and a
 * `BaseChainAbi` can map each on-chain address to its ABI.
 */
class ContractAbi implements EventAbi
{
    /** @var array<int, array<string, mixed>> */
    private array $abi;

    /** @var array<string, array<string, mixed>> name -> abi item */
    private array $byName = [];

    /** @var array<string, array<string, mixed>> topic0 (lowercase 0x...) -> abi item */
    private array $byTopic = [];

    public function __construct(private readonly string $name, string $abiPath)
    {
        $raw = file_get_contents($abiPath);
        if ($raw === false) {
            throw new RuntimeException("ContractAbi[$name]: cannot read $abiPath");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['abi']) || !is_array($decoded['abi'])) {
            throw new RuntimeException("ContractAbi[$name]: malformed JSON at $abiPath");
        }
        $this->abi = $decoded['abi'];
        foreach ($this->abi as $item) {
            if (($item['type'] ?? '') !== 'event') {
                continue;
            }
            $eventName = (string) ($item['name'] ?? '');
            if ($eventName === '') {
                continue;
            }
            $this->byName[$eventName] = $item;
            $this->byTopic[self::topicForEvent($item)] = $item;
        }
    }

    /** Logical contract name used as the handler-dispatch prefix (e.g. "Registry"). */
    public function name(): string
    {
        return $this->name;
    }

    public function eventByTopic(string $topic0): ?array
    {
        return $this->byTopic[strtolower($topic0)] ?? null;
    }

    public function eventByName(string $name): ?array
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function eventsByName(): array
    {
        return $this->byName;
    }

    /**
     * Canonical signature like Published(bytes32,uint32,address,...).
     *
     * @param array<string, mixed> $event ABI event item
     */
    public static function canonicalSignature(array $event): string
    {
        $name = (string) $event['name'];
        $inputs = $event['inputs'] ?? [];
        $types = array_map(fn ($i) => (string) $i['type'], $inputs);
        return $name . '(' . implode(',', $types) . ')';
    }

    /**
     * keccak256 of the canonical signature, lowercase hex with 0x prefix.
     *
     * @param array<string, mixed> $event ABI event item
     */
    public static function topicForEvent(array $event): string
    {
        return '0x' . strtolower(Keccak::hash(self::canonicalSignature($event), 256));
    }
}
