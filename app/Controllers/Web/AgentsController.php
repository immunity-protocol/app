<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Agent\Brokers\FleetMemberBroker;
use App\Models\Core\NetworkConfig;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * The /agents page: the live fleet roster (identity only — ENS / avatar / role /
 * online) and the download (run the template agent and join the network
 * yourself). The agentic-first showcase — "an immune system run by agents, for
 * agents". The rich, money-bearing activity feed now lives on the /dashboard.
 * First paint renders server-side; the roster stays fresh via the
 * /api/v1/agents/feed poller.
 */
final class AgentsController extends Controller
{
    /** All fleet roles, in display order (honest roles first, adversaries last). */
    private const ROLES = ['publisher', 'hunter', 'corroborator', 'trader', 'autoimmune', 'wolf'];

    #[Get('/agents')]
    public function index(): Response
    {
        $members = new FleetMemberBroker();

        $roster = $members->listRoster(100);
        $onlineByRole = $members->countOnlineByRole();
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
            'fleetPaused' => (new \App\Models\Agent\Brokers\FleetControlBroker())->isPaused(),
            'explorerUrl' => $network->blockExplorerUrl,
            'image'       => 'ghcr.io/immunity-protocol/agent',
            'repoUrl'     => 'https://github.com/immunity-protocol/agent',
        ]);
    }
}
