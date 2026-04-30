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
    // Nullable: the pricing overflow guard in MoralisPriceService returns
    // null on tokenAmounts that would overflow `numeric(20,6)` — typical
    // for `approve(MAX)` blocks where tokenAmount = 2^256-1. The block_event
    // still records (count goes up, audit trail intact); the column stays
    // NULL and the dashboard renders "-" instead of a phantom $99T figure.
    public ?string $value_protected_usd = null;
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
