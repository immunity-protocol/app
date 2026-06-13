<?php

declare(strict_types=1);

namespace App\Models\Agent\Brokers;

use App\Models\Core\Broker;

/**
 * Append-only log of fleet-agent actions (check / publish / corroborate /
 * challenge / scan). Written by the heartbeat receiver, read by the /agents
 * live feed (keyset cursor on id, newest-first).
 */
class FleetActivityBroker extends Broker
{
    /**
     * @param array<string, mixed> $a one activity row (validated by the caller)
     */
    public function insert(array $a): void
    {
        $this->db->query(
            "INSERT INTO agent.fleet_activity
                 (agent_id, role, display_name, action_type, action_summary,
                  status, antibody_imm_id, tx_hash, target, family)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $a['agent_id'], $a['role'], $a['display_name'], $a['action_type'],
                $a['action_summary'], $a['status'], $a['antibody_imm_id'] ?? null,
                $a['tx_hash'] ?? null, $a['target'] ?? null, $a['family'] ?? null,
            ]
        );
    }

    /**
     * Most recent rows, newest-first (first paint of the feed).
     *
     * @return \stdClass[]
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->select(
            "SELECT * FROM agent.fleet_activity ORDER BY id DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * Rows strictly newer than the client's last seen id (live poll prepend).
     * Passing null returns the latest window like findRecent.
     *
     * @return \stdClass[]
     */
    public function findSince(?int $sinceId, int $limit = 50): array
    {
        if ($sinceId === null) {
            return $this->findRecent($limit);
        }
        return $this->select(
            "SELECT * FROM agent.fleet_activity WHERE id > ? ORDER BY id DESC LIMIT ?",
            [$sinceId, $limit]
        );
    }

    public function countAll(): int
    {
        return (int) $this->selectValue("SELECT count(*) FROM agent.fleet_activity");
    }

    /**
     * Lifetime fleet totals for the stat tiles.
     *
     * @return array{checks:int, blocks:int, publishes:int}
     */
    public function fleetTotals(): array
    {
        $row = $this->selectOne(
            "SELECT
                 count(*) FILTER (WHERE action_type = 'check')                    AS checks,
                 count(*) FILTER (WHERE status = 'block')                          AS blocks,
                 count(*) FILTER (WHERE action_type IN ('publish', 'corroborate')) AS publishes
               FROM agent.fleet_activity"
        );
        return [
            'checks'    => $row !== null ? (int) $row->checks : 0,
            'blocks'    => $row !== null ? (int) $row->blocks : 0,
            'publishes' => $row !== null ? (int) $row->publishes : 0,
        ];
    }

    public function pruneOlderThan(int $hours): int
    {
        return $this->deleteRows(
            "DELETE FROM agent.fleet_activity WHERE occurred_at < now() - make_interval(hours => ?)",
            [$hours]
        );
    }
}
