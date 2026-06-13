<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Agent\Brokers\FleetActivityBroker;
use App\Models\Agent\Brokers\FleetMemberBroker;
use App\Models\Core\NetworkConfig;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * The /agents page: the live fleet roster + fleet stats + activity feed, and
 * the download (run the template agent and join the network yourself). The
 * agentic-first showcase — "an immune system run by agents, for agents".
 * First paint renders server-side; the page stays fresh via the
 * /api/v1/agents/feed poller.
 */
final class AgentsController extends Controller
{
    /** The three public template roles, in display order. */
    private const ROLES = ['publisher', 'hunter', 'corroborator'];

    #[Get('/agents')]
    public function index(): Response
    {
        $members = new FleetMemberBroker();
        $activityBroker = new FleetActivityBroker();

        $roster = $members->listRoster(100);
        $onlineByRole = $members->countOnlineByRole();
        $totals = $activityBroker->fleetTotals();
        $network = NetworkConfig::baseSepolia();

        $roleCounts = [];
        foreach (self::ROLES as $r) {
            $roleCounts[$r] = $onlineByRole[$r] ?? 0;
        }

        return $this->render('agents', [
            'roster'      => $roster,
            'online'      => $members->countOnline(),
            'total'       => $members->countTotal(),
            'roleCounts'  => $roleCounts,
            'totals'      => $totals,
            'activity'    => $activityBroker->findRecent(50),
            'explorerUrl' => $network->blockExplorerUrl,
            'image'       => 'ghcr.io/immunity-protocol/agent',
            'repoUrl'     => 'https://github.com/immunity-protocol/agent',
        ]);
    }
}
