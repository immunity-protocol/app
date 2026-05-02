<?php

declare(strict_types=1);

namespace App\Models\Demo\Brokers;

use App\Models\Core\Broker;

/**
 * Reader for demo.agent_activity. Drives the live "Agent activity" panel on
 * /dashboard. Each row is one observable thing an agent did this session
 * (allowed/blocked check, attempted attack, scanned a feed post, minted an
 * antibody, etc.) — see agents/src/context.ts ActivityRecord for the full
 * shape.
 *
 * The panel polls /api/v1/dashboard/activity every 2s; this broker uses a
 * keyset cursor on `id` so each tick only fetches what is new.
 */
class AgentActivityBroker extends Broker
{
    /**
     * Most recent activity rows newer than $sinceId, descending by id so the
     * client receives the latest row first. When $sinceId is null the call
     * returns the latest $limit rows total — used on first poll to populate
     * the panel without dumping the entire history.
     *
     * @return \stdClass[]
     */
    public function findSince(?int $sinceId, int $limit = 50): array
    {
        if ($sinceId !== null) {
            return $this->select(
                "SELECT id, agent_id, role, display_name, action_type,
                        action_summary, status, antibody_imm_id, tx_hash,
                        target, family, occurred_at
                   FROM demo.agent_activity
                  WHERE id > ?
               ORDER BY id DESC
                  LIMIT ?",
                [$sinceId, $limit]
            );
        }
        return $this->select(
            "SELECT id, agent_id, role, display_name, action_type,
                    action_summary, status, antibody_imm_id, tx_hash,
                    target, family, occurred_at
               FROM demo.agent_activity
           ORDER BY id DESC
              LIMIT ?",
            [$limit]
        );
    }

    /**
     * Paginated history slice for the /fleet-activities listing page.
     * Same column projection as findSince(), ordered newest first.
     *
     * @return \stdClass[]
     */
    public function findPage(int $offset, int $limit): array
    {
        return $this->select(
            "SELECT id, agent_id, role, display_name, action_type,
                    action_summary, status, antibody_imm_id, tx_hash,
                    target, family, occurred_at
               FROM demo.agent_activity
           ORDER BY id DESC
              LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * Total row count for pagination metadata.
     */
    public function countAll(): int
    {
        return $this->selectInt("SELECT count(*) FROM demo.agent_activity");
    }

    /**
     * Trim rows older than the given window so the table stays bounded
     * during long-running demos. Idempotent and best-effort; intended to be
     * called from a periodic job, not on every request.
     */
    public function pruneOlderThan(int $hours): int
    {
        return $this->deleteRows(
            "DELETE FROM demo.agent_activity
              WHERE occurred_at < now() - make_interval(hours => ?)",
            [$hours]
        );
    }
}
