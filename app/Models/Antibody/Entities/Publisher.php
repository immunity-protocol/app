<?php

declare(strict_types=1);

namespace App\Models\Antibody\Entities;

use App\Models\Core\HasByteaSerialization;
use Zephyrus\Data\Entity;

class Publisher extends Entity
{
    use HasByteaSerialization;

    public string $address;
    public ?string $ens = null;
    public int $antibodies_published;
    public int $successful_blocks;
    public string $total_earned_usdc;
    public string $total_staked_usdc;
    public int $successful_challenges_won;
    public int $challenges_lost;
    public string $first_seen_at;
    public string $last_active_at;

    // On-chain reputation mirror (Base Reputation + PublisherRegistrar events).
    // Display-only; the canonical score lives on the Reputation contract.
    public string $score = '0';
    public int $matured_count = 0;
    public int $challenges_won = 0;
    public int $slashed_count = 0;
    public string $genesis_granted = '0';
    public ?string $ens_node = null;
    public ?string $registration_bond = null;
    public ?string $registered_at = null;
    public bool $deregistered = false;

    /**
     * Coarse reputation tier from the score, mirroring the protocol's bands.
     * Genesis publishers start at 100; matured/won antibodies push higher.
     */
    public function reputationTier(): string
    {
        $score = (float) $this->score;
        return match (true) {
            $score >= 200 => 'trusted',
            $score >= 100 => 'established',
            $score > 0    => 'emerging',
            default       => 'unranked',
        };
    }

    /**
     * @return list<string>
     */
    protected static function byteaProperties(): array
    {
        return ['address', 'ens_node'];
    }
}
