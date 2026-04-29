<?php

declare(strict_types=1);

namespace App\Models\Antibody\Entities;

use App\Models\Core\HasByteaSerialization;
use Zephyrus\Data\Entity;

class Entry extends Entity
{
    use HasByteaSerialization;

    public int $id;
    public string $keccak_id;
    public string $imm_id;
    public string $type;
    public ?string $flavor = null;
    public string $verdict;
    public int $confidence;
    public int $severity;
    public string $status;
    public $primary_matcher;
    public ?string $primary_matcher_hash = null;
    public $secondary_matchers;
    public string $context_hash;
    public string $evidence_cid;
    public ?string $embedding_hash = null;
    public ?string $embedding_cid = null;
    public string $stake_lock_until;
    public ?string $expires_at = null;
    public string $publisher;
    public ?string $publisher_ens = null;
    public string $stake_amount;
    public string $attestation;
    public ?string $publish_tx_hash = null;
    public ?string $seed_source = null;
    public ?string $redacted_reasoning = null;
    public string $created_at;
    public string $updated_at;

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isMalicious(): bool
    {
        return $this->verdict === 'malicious';
    }

    /**
     * @return list<string>
     */
    protected static function byteaProperties(): array
    {
        return [
            'keccak_id', 'primary_matcher_hash', 'context_hash', 'evidence_cid',
            'embedding_hash', 'embedding_cid',
            'publisher', 'attestation', 'publish_tx_hash',
        ];
    }
}
