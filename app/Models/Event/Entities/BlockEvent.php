<?php

declare(strict_types=1);

namespace App\Models\Event\Entities;

use App\Models\Core\HasByteaSerialization;
use Zephyrus\Data\Entity;

class BlockEvent extends Entity
{
    use HasByteaSerialization;

    public int $id;
    public int $check_event_id;
    public int $entry_id;
    public string $agent_id;
    public string $value_protected_usd;
    public ?string $tx_hash_attempt = null;
    public int $chain_id;
    public string $occurred_at;

    /**
     * @return list<string>
     */
    protected static function byteaProperties(): array
    {
        return ['tx_hash_attempt'];
    }
}
