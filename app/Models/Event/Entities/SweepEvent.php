<?php

declare(strict_types=1);

namespace App\Models\Event\Entities;

use App\Models\Core\HasByteaSerialization;
use Zephyrus\Data\Entity;

class SweepEvent extends Entity
{
    use HasByteaSerialization;

    public int $id;
    public string $sweeper;
    public int $num_released;
    public string $bounty_paid;
    public string $occurred_at;
    public int $block_number;
    public string $tx_hash;
    public int $log_index;

    /**
     * @return list<string>
     */
    protected static function byteaProperties(): array
    {
        return ['sweeper', 'tx_hash'];
    }
}
