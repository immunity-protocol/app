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

    /**
     * Online agents in a single role, with display_name + last_seen.
     * Used by playground card 09 (inject prompt) to populate its target
     * dropdown without dragging the entire roster snapshot client-side.
     *
     * @return \stdClass[] rows: { agent_id, display_name, last_seen }
     */
    public function listOnlineByRole(string $role): array
    {
        return $this->select(
            "SELECT agent_id, display_name, last_seen
               FROM demo.agent_heartbeat
              WHERE role = ?
                AND last_seen >= now() - make_interval(secs => ?)
           ORDER BY agent_id",
            [$role, self::ONLINE_WINDOW_SECONDS]
        );
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

    /**
     * Pick a random agent_id from the online set, optionally filtered by role.
     * Returns null if none online.
     */
    public function pickRandomOnline(?string $role = null): ?string
    {
        $sql = "SELECT agent_id FROM demo.agent_heartbeat
                 WHERE last_seen >= now() - make_interval(secs => ?)";
        $params = [self::ONLINE_WINDOW_SECONDS];
        if ($role !== null) {
            $sql .= " AND role = ?";
            $params[] = $role;
        }
        $sql .= " ORDER BY random() LIMIT 1";
        $row = $this->selectOne($sql, $params);
        return $row !== null ? (string) $row->agent_id : null;
    }

    /**
     * Full agent roster with per-agent 24h check / block counts and an
     * `online` flag derived from `last_seen`. Sorted alphabetically by
     * `agent_id` so the dashboard can map each row to a stable node index
     * on the propagation map.
     *
     * @return \stdClass[]  rows: { agent_id, role, display_name, last_seen, online, checks_24h, blocks_24h }
     */
    public function listAllWithStats(int $limit = 60): array
    {
        return $this->select(
            "SELECT
                 h.agent_id,
                 h.role,
                 h.display_name,
                 h.last_seen,
                 (h.last_seen >= now() - make_interval(secs => ?)) AS online,
                 coalesce(c.n, 0) AS checks_24h,
                 coalesce(b.n, 0) AS blocks_24h
               FROM demo.agent_heartbeat h
          LEFT JOIN (
                 SELECT agent_id, count(*) AS n
                   FROM event.check_event
                  WHERE occurred_at >= now() - interval '24 hours'
               GROUP BY agent_id
               ) c ON c.agent_id = h.agent_id
          LEFT JOIN (
                 SELECT agent_id, count(*) AS n
                   FROM event.block_event
                  WHERE occurred_at >= now() - interval '24 hours'
               GROUP BY agent_id
               ) b ON b.agent_id = h.agent_id
           ORDER BY h.agent_id
              LIMIT ?",
            [self::ONLINE_WINDOW_SECONDS, $limit]
        );
    }
}
