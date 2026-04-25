<?php

declare(strict_types=1);

namespace App\Models\Antibody\Entities;

use Zephyrus\Data\Entity;

class Mirror extends Entity
{
    public int $id;
    public int $entry_id;
    public int $chain_id;
    public string $chain_name;
    public string $mirror_tx_hash;
    public string $mirrored_at;
    public string $status;
    public string $relayer_address;

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
