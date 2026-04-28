<?php

declare(strict_types=1);

namespace App\Models\Demo\Brokers;

use App\Models\Core\Broker;

class HeartbeatBroker extends Broker
{
    /**
     * Liveness window: a heartbeat is considered "online" if its last_seen is
     * within this many seconds. Agents heartbeat every 60s; 120s gives one
     * missed tick of slack before we drop them.
     */
    public const ONLINE_WINDOW_SECONDS = 120;

    /**
     * @return array<string, int> map of role -> live count
     */
    public function countOnlineByRole(): array
    {
        $rows = $this->select(
            "SELECT role, count(*) AS n
               FROM demo.agent_heartbeat
              WHERE last_seen >= now() - make_interval(secs => ?)
              GROUP BY role",
            [self::ONLINE_WINDOW_SECONDS]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->role] = (int) $r->n;
        }
        return $out;
    }

    public function countOnline(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM demo.agent_heartbeat
              WHERE last_seen >= now() - make_interval(secs => ?)",
            [self::ONLINE_WINDOW_SECONDS]
        );
    }

    public function countTotal(): int
    {
        return (int) $this->selectValue("SELECT count(*) FROM demo.agent_heartbeat");
    }
}
