<?php

declare(strict_types=1);

namespace App\Models\Demo\Brokers;

use App\Models\Core\Broker;
use stdClass;

class FleetStateBroker extends Broker
{
    public function get(): stdClass
    {
        $row = $this->selectOne("SELECT * FROM demo.fleet_state WHERE id = 1");
        if ($row === null) {
            // The init script seeds this row, so absence is recoverable but
            // worth noting. Fall back to an unpaused default.
            $row = (object) ['id' => 1, 'ambient_paused' => false, 'paused_at' => null];
        }
        return $row;
    }

    public function pause(): void
    {
        $this->db->query(
            "UPDATE demo.fleet_state SET ambient_paused = true, paused_at = now() WHERE id = 1"
        );
    }

    public function resume(): void
    {
        $this->db->query(
            "UPDATE demo.fleet_state SET ambient_paused = false, paused_at = NULL WHERE id = 1"
        );
    }
}
