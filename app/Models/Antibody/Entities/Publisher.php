<?php

declare(strict_types=1);

namespace App\Models\Antibody\Entities;

use Zephyrus\Data\Entity;

class Publisher extends Entity
{
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
}
