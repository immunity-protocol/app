<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Agent\Brokers\FleetActivityBroker;
use App\Models\Core\NetworkConfig;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class DashboardController extends Controller
{
    #[Get('/dashboard')]
    public function index(): Response
    {
        // The dashboard leads with the live fleet activity feed — the rich,
        // money-bearing stream of what the agents are actually doing (checks,
        // blocks, publishes, challenges). Renders server-side on first paint and
        // stays fresh via the /api/v1/agents/feed poller. The network stat tiles
        // (incl. value protected) ride the existing /api/v1/network/stats poller.
        $network = NetworkConfig::baseSepolia();
        $activity = (new FleetActivityBroker())->findRecent(50);
        return $this->render('dashboard', [
            'activity'    => $activity,
            'explorerUrl' => $network->blockExplorerUrl,
        ]);
    }
}
