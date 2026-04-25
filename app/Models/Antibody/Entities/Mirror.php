<?php

declare(strict_types=1);

namespace App\Models\Antibody\Entities;

use App\Models\Core\HasByteaSerialization;
use Zephyrus\Data\Entity;

class Mirror extends Entity
{
    use HasByteaSerialization;

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

    /**
     * @return list<string>
     */
    protected static function byteaProperties(): array
    {
        return ['mirror_tx_hash', 'relayer_address'];
    }
}
