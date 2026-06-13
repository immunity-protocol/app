<?php

declare(strict_types=1);

namespace App\Models\Agent\Brokers;

use App\Models\Core\Broker;

/**
 * The singleton fleet pause flag (agent.fleet_control). Agents poll it via
 * GET /v1/agents/control and idle while paused; the playground flips it.
 */
class FleetControlBroker extends Broker
{
    public function isPaused(): bool
    {
        return (bool) $this->selectValue("SELECT paused FROM agent.fleet_control WHERE id = 1");
    }

    public function setPaused(bool $paused): void
    {
        $this->db->query(
            "UPDATE agent.fleet_control SET paused = ?, updated_at = now() WHERE id = 1",
            [$paused ? 't' : 'f']
        );
    }
}
