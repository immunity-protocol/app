<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Agent\Brokers\FleetActivityBroker;
use App\Models\Agent\Brokers\FleetMemberBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * Live poll for the /agents page. One tick returns the full roster (with
 * online flags + per-agent counters), the fleet stat totals, and any activity
 * rows newer than the client's keyset cursor. Drives the roster table and the
 * live activity feed without a full reload.
 */
final class AgentsController extends Controller
{
    private const ACTIVITY_LIMIT = 50;

    #[Get('/agents/feed')]
    public function feed(Request $request): Response
    {
        $sinceParam = $request->query('activity_since');
        $since = is_string($sinceParam) && $sinceParam !== '' ? (int) $sinceParam : null;

        $members = new FleetMemberBroker();
        $activityBroker = new FleetActivityBroker();

        $roster = $members->listRoster(100);
        $activity = $activityBroker->findSince($since, self::ACTIVITY_LIMIT);
        $totals = $activityBroker->fleetTotals();

        $nextSince = $since ?? 0;
        foreach ($activity as $row) {
            $id = (int) $row->id;
            if ($id > $nextSince) {
                $nextSince = $id;
            }
        }

        return Response::json([
            'stats' => [
                'online'         => $members->countOnline(),
                'total'          => $members->countTotal(),
                'online_by_role' => $members->countOnlineByRole(),
                'checks'         => $totals['checks'],
                'blocks'         => $totals['blocks'],
                'publishes'      => $totals['publishes'],
            ],
            'roster'              => array_map([$this, 'projectMember'], $roster),
            'activity'            => array_map([$this, 'projectActivity'], $activity),
            'next_activity_since' => $nextSince,
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function projectMember(\stdClass $r): array
    {
        return [
            'agent_id'     => (string) $r->agent_id,
            'role'         => (string) $r->role,
            'display_name' => (string) $r->display_name,
            'wallet'       => $r->wallet,
            'ens'          => $r->ens,
            'version'      => (string) $r->version,
            'last_seen'    => (string) $r->last_seen,
            'online'       => (bool) $r->online,
            'checks'       => (int) $r->checks,
            'blocks'       => (int) $r->blocks,
            'publishes'    => (int) $r->publishes,
        ];
    }

    private function projectActivity(\stdClass $r): array
    {
        return [
            'id'              => (int) $r->id,
            'agent_id'        => (string) $r->agent_id,
            'role'            => (string) $r->role,
            'display_name'    => (string) $r->display_name,
            'action_type'     => (string) $r->action_type,
            'action_summary'  => (string) $r->action_summary,
            'status'          => (string) $r->status,
            'antibody_imm_id' => $r->antibody_imm_id,
            'tx_hash'         => $r->tx_hash,
            'target'          => $r->target,
            'family'          => $r->family,
            'occurred_at'     => (string) $r->occurred_at,
        ];
    }
}
