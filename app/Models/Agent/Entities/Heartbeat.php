<?php

declare(strict_types=1);

namespace App\Models\Agent\Entities;

use Zephyrus\Data\Entity;

class Heartbeat extends Entity
{
    public string $agent_id;
    public ?string $agent_ens = null;
    public string $agent_role;
    public string $last_seen;
    public int $peer_count;
    public string $version;
    public $metadata;
}
