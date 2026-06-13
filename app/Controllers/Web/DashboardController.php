<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Antibody\Services\EntryService;
use App\Models\Core\NetworkConfig;
use App\Models\Event\Brokers\ContractEventBroker;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class DashboardController extends Controller
{
    #[Get('/dashboard')]
    public function index(): Response
    {
        // The dashboard is a live on-chain event log: the recent contract
        // events (CRE verifications, jury verdicts, challenges, antibody
        // lifecycle) the indexer has ingested from Base Sepolia, plus a recent
        // antibodies panel. Both render server-side on first paint and stay
        // fresh via the dashboard activity poller.
        $network = NetworkConfig::baseSepolia();
        $events = (new ContractEventBroker())->findRecentForFeed(60);
        $recent = (new EntryService())->findRecentWithStats(8);
        return $this->render('dashboard', [
            'events'           => $events,
            'recentAntibodies' => $recent,
            'explorerUrl'      => $network->blockExplorerUrl,
        ]);
    }
}
