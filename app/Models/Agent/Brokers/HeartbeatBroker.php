<?php

declare(strict_types=1);

namespace App\Models\Agent\Brokers;

use App\Models\Core\Broker;

class HeartbeatBroker extends Broker
{
    /**
     * @param array<string, mixed> $data must include agent_id, agent_role, version
     */
    public function upsert(array $data): void
    {
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
        $updates = [];
        foreach ($cols as $c) {
            if ($c === 'agent_id') {
                continue;
            }
            $updates[] = '"' . $c . '" = EXCLUDED."' . $c . '"';
        }
        $updates[] = 'last_seen = now()';
        $updateClause = implode(', ', $updates);
        $sql = "INSERT INTO agent.heartbeat ($colList) VALUES ($placeholders)
                ON CONFLICT (agent_id) DO UPDATE SET $updateClause";
        $this->db->query($sql, array_values($data));
    }

    public function countOnline(int $withinSeconds = 300): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM agent.heartbeat
             WHERE last_seen >= now() - (? || ' seconds')::interval",
            [(string) $withinSeconds]
        );
    }

    /**
     * @return array<string, int> map of role -> count
     */
    public function countByRole(): array
    {
        $rows = $this->select(
            "SELECT agent_role::text AS agent_role, count(*) AS n
             FROM agent.heartbeat GROUP BY agent_role"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r->agent_role] = (int) $r->n;
        }
        return $out;
    }

    /**
     * @return \stdClass[]
     */
    public function findAll(int $limit = 200): array
    {
        return $this->select(
            "SELECT * FROM agent.heartbeat ORDER BY last_seen DESC LIMIT ?",
            [$limit]
        );
    }

    public function maxLastSeen(): ?string
    {
        $value = $this->selectValue("SELECT max(last_seen) FROM agent.heartbeat");
        return $value === null ? null : (string) $value;
    }
}
