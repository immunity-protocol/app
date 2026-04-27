<?php

declare(strict_types=1);

namespace App\Models\Indexer\Chain;

use kornrunner\Keccak;
use RuntimeException;

/**
 * Loads the Registry ABI fixture and exposes lookups by event name and by
 * topic0 (keccak256 of the canonical event signature). Pure-data class with
 * no side effects.
 */
class RegistryAbi implements EventAbi
{
    /**
     * Galileo deploy block — kept here because it's a per-deploy artifact, not
     * network-wide config. Override via `OG_REGISTRY_DEPLOY_BLOCK` env var when
     * the contract is redeployed. (Chain id, registry address, RPC URL, etc.
     * live on `App\Models\Core\NetworkConfig`.)
     */
    public const DEPLOY_BLOCK_DEFAULT = 30057380;

    /** @var array<int, array<string, mixed>> */
    private array $abi;

    /** @var array<string, array<string, mixed>> name -> abi item */
    private array $byName = [];

    /** @var array<string, array<string, mixed>> topic0 (lowercase 0x...) -> abi item */
    private array $byTopic = [];

    public function __construct(?string $abiPath = null)
    {
        $abiPath ??= __DIR__ . '/abi/Registry.json';
        $raw = file_get_contents($abiPath);
        if ($raw === false) {
            throw new RuntimeException("RegistryAbi: cannot read $abiPath");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['abi']) || !is_array($decoded['abi'])) {
            throw new RuntimeException("RegistryAbi: malformed JSON at $abiPath");
        }
        $this->abi = $decoded['abi'];
        foreach ($this->abi as $item) {
            if (($item['type'] ?? '') !== 'event') {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $this->byName[$name] = $item;
            $this->byTopic[self::topicForEvent($item)] = $item;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function eventsByName(): array
    {
        return $this->byName;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function eventsByTopic(): array
    {
        return $this->byTopic;
    }

    public function eventByTopic(string $topic0): ?array
    {
        $topic0 = strtolower($topic0);
        return $this->byTopic[$topic0] ?? null;
    }

    public function eventByName(string $name): ?array
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * Compute canonical signature like AntibodyPublished(bytes32,uint32,...)
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
        $hash = Keccak::hash(self::canonicalSignature($event), 256);
        return '0x' . strtolower($hash);
    }
}
