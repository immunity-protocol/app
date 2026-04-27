<?php

declare(strict_types=1);

namespace App\Models\Indexer\Chain;

use kornrunner\Keccak;
use RuntimeException;

/**
 * Loads the Mirror ABI fixture (events only) and exposes the same lookup
 * surface as RegistryAbi: event-by-name, event-by-topic0.
 *
 * Used by the Sepolia (and future per-chain) EventPoller instances so the
 * indexer can decode AntibodyMirrored / AntibodyUnmirrored / AddressBlocked
 * events emitted by Mirror contracts.
 */
class MirrorAbi implements EventAbi
{
    /** @var array<int, array<string, mixed>> */
    private array $abi;

    /** @var array<string, array<string, mixed>> name -> abi item */
    private array $byName = [];

    /** @var array<string, array<string, mixed>> topic0 -> abi item */
    private array $byTopic = [];

    public function __construct(?string $abiPath = null)
    {
        $abiPath ??= __DIR__ . '/abi/Mirror.json';
        $raw = file_get_contents($abiPath);
        if ($raw === false) {
            throw new RuntimeException("MirrorAbi: cannot read $abiPath");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['abi']) || !is_array($decoded['abi'])) {
            throw new RuntimeException("MirrorAbi: malformed JSON at $abiPath");
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

    /** @return array<string, array<string, mixed>> */
    public function eventsByName(): array
    {
        return $this->byName;
    }

    /** @return array<string, array<string, mixed>> */
    public function eventsByTopic(): array
    {
        return $this->byTopic;
    }

    public function eventByTopic(string $topic0): ?array
    {
        return $this->byTopic[strtolower($topic0)] ?? null;
    }

    public function eventByName(string $name): ?array
    {
        return $this->byName[$name] ?? null;
    }

    /** @param array<string, mixed> $event */
    public static function canonicalSignature(array $event): string
    {
        $name = (string) $event['name'];
        $inputs = $event['inputs'] ?? [];
        $types = array_map(fn ($i) => (string) $i['type'], $inputs);
        return $name . '(' . implode(',', $types) . ')';
    }

    /** @param array<string, mixed> $event */
    public static function topicForEvent(array $event): string
    {
        $hash = Keccak::hash(self::canonicalSignature($event), 256);
        return '0x' . strtolower($hash);
    }
}
