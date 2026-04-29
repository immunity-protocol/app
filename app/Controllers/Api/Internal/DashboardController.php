<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Antibody\Services\EntryService;
use App\Models\Demo\Brokers\AgentActivityBroker;
use App\Models\Demo\Brokers\HeartbeatBroker;
use App\Models\Event\Brokers\BlockEventBroker;
use App\Models\Event\Brokers\CheckEventBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * Single coordinated poll endpoint for the dashboard. One tick returns:
 *   - the agent roster with online flag + 24h counters (drives the right rail)
 *   - check + block events since the client's last cursor (drives the map)
 *   - the latest 10 antibodies with stats (drives the active-registry table)
 *
 * Stats tiles still ride the existing /api/v1/network/stats poller because
 * that endpoint also computes 1h deltas from the network.stat snapshots.
 */
final class DashboardController extends Controller
{
    private const EVENT_LIMIT = 200;
    private const ACTIVITY_LIMIT = 50;

    #[Get('/dashboard/activity')]
    public function index(Request $request): Response
    {
        $sinceParam = $request->query('since');
        $since = $this->resolveSince(is_string($sinceParam) ? $sinceParam : null);

        // Activity uses a separate keyset cursor on demo.agent_activity.id so
        // it advances independently of the time-based event cursor.
        $activitySinceParam = $request->query('activity_since');
        $activitySince = is_string($activitySinceParam) && $activitySinceParam !== ''
            ? (int) $activitySinceParam
            : null;

        $heartbeats = (new HeartbeatBroker())->listAllWithStats(60);
        $checks = (new CheckEventBroker())->findRecentSince($since, self::EVENT_LIMIT);
        $blocks = (new BlockEventBroker())->findRecentSince($since, self::EVENT_LIMIT);
        $entries = (new EntryService())->findRecentWithStats(10);
        $activity = (new AgentActivityBroker())->findSince($activitySince, self::ACTIVITY_LIMIT);

        $nextSince = $this->advanceCursor($since, $checks, $blocks);
        $nextActivitySince = $this->advanceActivityCursor($activitySince, $activity);

        return Response::json([
            'agents'              => array_map([$this, 'projectAgent'], $heartbeats),
            'checks'              => array_map([$this, 'projectCheck'], $checks),
            'blocks'              => array_map([$this, 'projectBlock'], $blocks),
            'recent_entries'      => array_map([$this, 'projectEntry'], $entries),
            'activity'            => array_map([$this, 'projectActivity'], $activity),
            'next_since'          => $nextSince,
            'next_activity_since' => $nextActivitySince,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param \stdClass[] $rows
     */
    private function advanceActivityCursor(?int $current, array $rows): int
    {
        $max = $current ?? 0;
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if ($id > $max) {
                $max = $id;
            }
        }
        return $max;
    }

    private function projectActivity(\stdClass $row): array
    {
        return [
            'id'              => (int) $row->id,
            'agent_id'        => (string) $row->agent_id,
            'role'            => (string) $row->role,
            'display_name'    => (string) $row->display_name,
            'action_type'     => (string) $row->action_type,
            'action_summary'  => (string) $row->action_summary,
            'status'          => (string) $row->status,
            'antibody_imm_id' => $row->antibody_imm_id,
            'tx_hash'         => $row->tx_hash,
            'target'          => $row->target,
            'family'          => $row->family,
            'occurred_at'     => (string) $row->occurred_at,
        ];
    }

    private function resolveSince(?string $raw): string
    {
        if ($raw !== null && $raw !== '') {
            $ts = strtotime($raw);
            if ($ts !== false) {
                return gmdate('Y-m-d H:i:sP', $ts);
            }
        }
        // First poll: only return events from the last minute so we don't
        // dump a massive backlog into the page on initial load.
        return gmdate('Y-m-d H:i:sP', time() - 60);
    }

    /**
     * @param \stdClass[] $checks
     * @param \stdClass[] $blocks
     */
    private function advanceCursor(string $since, array $checks, array $blocks): string
    {
        $maxTs = $since;
        foreach ([$checks, $blocks] as $rows) {
            foreach ($rows as $row) {
                $ts = (string) $row->occurred_at;
                if (strcmp($ts, $maxTs) > 0) {
                    $maxTs = $ts;
                }
            }
        }
        // If nothing new arrived, advance to "now" so the next poll only
        // gets fresh events. Otherwise keep the latest event timestamp so
        // we don't lose any straggler that lands at the same instant.
        if ($maxTs === $since) {
            return gmdate('Y-m-d H:i:sP');
        }
        return $maxTs;
    }

    private function projectAgent(\stdClass $row): array
    {
        return [
            'agent_id'     => (string) $row->agent_id,
            'role'         => (string) $row->role,
            'display_name' => (string) $row->display_name,
            'last_seen'    => (string) $row->last_seen,
            'online'       => (bool) $row->online,
            'checks_24h'   => (int) $row->checks_24h,
            'blocks_24h'   => (int) $row->blocks_24h,
        ];
    }

    private function projectCheck(\stdClass $row): array
    {
        return [
            'agent_id'  => (string) $row->agent_id,
            'ts'        => (string) $row->occurred_at,
            'cache_hit' => (bool) $row->cache_hit,
        ];
    }

    private function projectBlock(\stdClass $row): array
    {
        return [
            'agent_id' => (string) $row->agent_id,
            'ts'       => (string) $row->occurred_at,
            'entry_id' => $row->entry_id !== null ? (int) $row->entry_id : null,
        ];
    }

    private function projectEntry(\stdClass $row): array
    {
        return [
            'imm_id'              => (string) $row->imm_id,
            'type'                => (string) $row->type,
            'verdict'             => (string) $row->verdict,
            'reasoning'           => (string) ($row->redacted_reasoning ?? ''),
            'publisher_ens'       => $row->publisher_ens,
            'publisher_hex'       => (string) $row->publisher_hex,
            'cache_hits'          => (int) $row->cache_hits,
            'block_count'         => (int) $row->block_count,
            'mirror_count'        => (int) $row->mirror_count,
            'value_protected_usd' => (string) $row->value_protected_usd,
            'last_block_at'       => $row->last_block_at,
        ];
    }
}
