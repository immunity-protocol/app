<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Core\NetworkConfig;
use App\Models\Event\Brokers\ContractEventBroker;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class DashboardController extends Controller
{
    #[Get('/dashboard')]
    public function index(): Response
    {
        // The dashboard is a single full-page live on-chain event log: the
        // recent contract events (CRE verifications, jury verdicts, challenges,
        // antibody lifecycle) the indexer has ingested from Base Sepolia. Renders
        // server-side on first paint and stays fresh via the activity poller.
        $network = NetworkConfig::baseSepolia();
        $events = (new ContractEventBroker())->findRecentForFeed(200);
        return $this->render('dashboard', [
            'events'      => $events,
            'explorerUrl' => $network->blockExplorerUrl,
        ]);
    }
}
