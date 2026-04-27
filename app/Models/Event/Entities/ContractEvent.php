<?php

declare(strict_types=1);

namespace App\Models\Event\Entities;

use App\Models\Core\HasByteaSerialization;
use Zephyrus\Data\Entity;

class ContractEvent extends Entity
{
    use HasByteaSerialization;

    public int $id;
    public string $event_name;
    public $payload;
    public int $block_number;
    public string $tx_hash;
    public int $log_index;
    public string $occurred_at;

    /**
     * @return list<string>
     */
    protected static function byteaProperties(): array
    {
        return ['tx_hash'];
    }
}
