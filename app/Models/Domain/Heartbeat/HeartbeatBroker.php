<?php

declare(strict_types=1);

namespace App\Models\Domain\Heartbeat;

use Zephyrus\Data\Broker;

final class HeartbeatBroker extends Broker
{
    /**
     * Insert or refresh a heartbeat. last_seen always becomes "now" on conflict.
     *
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
        $sql = "INSERT INTO agent_heartbeats ($colList) VALUES ($placeholders)
                ON CONFLICT (agent_id) DO UPDATE SET $updateClause";
        $this->db->query($sql, array_values($data));
    }

    /**
     * Count agents whose last_seen is within $withinSeconds of now.
     */
    public function countOnline(int $withinSeconds = 300): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM agent_heartbeats
             WHERE last_seen >= now() - (? || ' seconds')::interval",
            [(string) $withinSeconds]
        );
    }

    /**
     * @return array<string, int> map of agent_role -> count
     */
    public function countByRole(): array
    {
        $rows = $this->select(
            "SELECT agent_role::text AS agent_role, count(*) AS n
             FROM agent_heartbeats GROUP BY agent_role"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r->agent_role] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Most recent oldest-last_seen first.
     *
     * @return Heartbeat[]
     */
    public function findAll(int $limit = 200): array
    {
        $rows = $this->select(
            "SELECT * FROM agent_heartbeats ORDER BY last_seen DESC LIMIT ?",
            [$limit]
        );
        return array_map(Heartbeat::fromRow(...), $rows);
    }

    public function maxLastSeen(): ?string
    {
        $value = $this->selectValue("SELECT max(last_seen) FROM agent_heartbeats");
        return $value === null ? null : (string) $value;
    }
}
