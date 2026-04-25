<?php

declare(strict_types=1);

namespace App\Models\Event\Entities;

use Zephyrus\Data\Entity;

class BlockEvent extends Entity
{
    public int $id;
    public int $check_event_id;
    public int $entry_id;
    public string $agent_id;
    public string $value_protected_usd;
    public ?string $tx_hash_attempt = null;
    public int $chain_id;
    public string $occurred_at;
}
