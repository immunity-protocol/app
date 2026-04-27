<?php

declare(strict_types=1);

namespace App\Models\Indexer\Entities;

use App\Models\Core\HasByteaSerialization;
use Zephyrus\Data\Entity;

class HydrationJob extends Entity
{
    use HasByteaSerialization;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public int $id;
    public string $antibody_keccak_id;
    public string $evidence_cid;
    public string $enqueued_at;
    public int $attempts = 0;
    public ?string $last_error = null;
    public string $status = self::STATUS_PENDING;

    /**
     * @return list<string>
     */
    protected static function byteaProperties(): array
    {
        return ['antibody_keccak_id', 'evidence_cid'];
    }
}
