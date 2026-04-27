<?php

declare(strict_types=1);

namespace App\Models\Indexer\Chain;

/**
 * Minimal contract that EventDecoder requires from any ABI source. Lets the
 * decoder serve both Registry (0G) and Mirror (Sepolia, future chains) without
 * a hard dependency on the concrete class.
 */
interface EventAbi
{
    /**
     * @return array<string, mixed>|null  ABI item for the matching event, or null if topic0 is unknown
     */
    public function eventByTopic(string $topic0): ?array;
}
