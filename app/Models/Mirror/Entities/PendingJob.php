<?php

declare(strict_types=1);

namespace App\Models\Mirror\Entities;

use App\Models\Core\HasByteaSerialization;
use Zephyrus\Data\Entity;

class PendingJob extends Entity
{
    use HasByteaSerialization;

    public const TYPE_MIRROR         = 'mirror';
    public const TYPE_MIRROR_ADDRESS = 'mirror_address';
    public const TYPE_UNMIRROR       = 'unmirror';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_IN_FLIGHT = 'in_flight';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED    = 'failed';

    public int $id;
    public string $keccak_id;
    public int $target_chain_id;
    public string $job_type;
    public string $payload = '{}';
    public string $enqueued_at;
    public string $next_attempt_at;
    public int $attempts = 0;
    public ?string $last_error = null;
    public string $status = self::STATUS_PENDING;
    public ?string $tx_hash = null;
    public ?string $sent_at = null;
    public ?string $confirmed_at = null;
    public ?string $claimed_at = null;

    /**
     * @return list<string>
     */
    protected static function byteaProperties(): array
    {
        return ['keccak_id', 'tx_hash'];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadArray(): array
    {
        $decoded = json_decode($this->payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}
