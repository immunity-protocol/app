<?php

declare(strict_types=1);

namespace App\Models\Agent\Brokers;

use App\Models\Core\Broker;

/**
 * The live fleet roster: one row per template agent, upserted on every
 * heartbeat. Reads back the roster (with online flag) + role counts for the
 * /agents page. "Online" = a heartbeat within ONLINE_WINDOW_SECONDS; agents
 * heartbeat ~every 60s, so 150s tolerates one missed tick plus jitter.
 */
class FleetMemberBroker extends Broker
{
    public const ONLINE_WINDOW_SECONDS = 150;

    /**
     * Upsert one heartbeat keyed on agent_id. first_seen is preserved; the
     * mutable columns + last_seen are refreshed. A null wallet/ens never
     * overwrites a previously-bound value (the agent reports "0x"/null before
     * its SDK identity resolves, then the real values once it does).
     */
    public function upsert(
        string $agentId,
        string $role,
        string $displayName,
        ?string $wallet,
        ?string $ens,
        string $version
    ): void {
        $this->db->query(
            "INSERT INTO agent.fleet_member (agent_id, role, display_name, wallet, ens, version)
                  VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (agent_id) DO UPDATE SET
                  role         = EXCLUDED.role,
                  display_name = EXCLUDED.display_name,
                  wallet       = COALESCE(EXCLUDED.wallet, agent.fleet_member.wallet),
                  ens          = COALESCE(EXCLUDED.ens, agent.fleet_member.ens),
                  version      = EXCLUDED.version,
                  last_seen    = now()",
            [$agentId, $role, $displayName, $wallet, $ens, $version]
        );
    }

    public function countTotal(): int
    {
        return (int) $this->selectValue("SELECT count(*) FROM agent.fleet_member");
    }

    public function countOnline(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM agent.fleet_member
              WHERE last_seen >= now() - make_interval(secs => ?)",
            [self::ONLINE_WINDOW_SECONDS]
        );
    }

    /**
     * @return array<string, int> map of role -> online count
     */
    public function countOnlineByRole(): array
    {
        $rows = $this->select(
            "SELECT role, count(*) AS n
               FROM agent.fleet_member
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
     * Full roster with an online flag and per-agent lifetime activity counters
     * (checks, blocks, publishes) joined from agent.fleet_activity. Online rows
     * first, then most-recently-seen.
     *
     * @return \stdClass[] rows: { agent_id, role, display_name, wallet, ens,
     *   version, last_seen, online, checks, blocks, publishes }
     */
    public function listRoster(int $limit = 100): array
    {
        return $this->select(
            "SELECT
                 m.agent_id,
                 m.role,
                 m.display_name,
                 m.wallet,
                 m.ens,
                 m.version,
                 m.last_seen,
                 (m.last_seen >= now() - make_interval(secs => ?)) AS online,
                 coalesce(a.checks, 0)    AS checks,
                 coalesce(a.blocks, 0)    AS blocks,
                 coalesce(a.publishes, 0) AS publishes
               FROM agent.fleet_member m
          LEFT JOIN (
                 SELECT agent_id,
                        count(*) FILTER (WHERE action_type = 'check')                       AS checks,
                        count(*) FILTER (WHERE status = 'block')                             AS blocks,
                        count(*) FILTER (WHERE action_type IN ('publish', 'corroborate'))    AS publishes
                   FROM agent.fleet_activity
               GROUP BY agent_id
               ) a ON a.agent_id = m.agent_id
           ORDER BY online DESC, m.last_seen DESC
              LIMIT ?",
            [self::ONLINE_WINDOW_SECONDS, $limit]
        );
    }
}
