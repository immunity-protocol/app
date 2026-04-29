<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Antibody\Services\EntryService;
use App\Models\Demo\Brokers\AgentActivityBroker;
use App\Models\Demo\Brokers\HeartbeatBroker;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class DashboardController extends Controller
{
    #[Get('/dashboard')]
    public function index(): Response
    {
        // Server-side first paint for both surfaces that get live-updated by
        // the dashboard activity poller (/api/v1/dashboard/activity), so the
        // page never shows a "loading" flash before the first tick lands.
        $recent = (new EntryService())->findRecentWithStats(10);
        $agents = (new HeartbeatBroker())->listAllWithStats(60);
        $activity = (new AgentActivityBroker())->findSince(null, 30);
        return $this->render('dashboard', [
            'recentAntibodies' => $recent,
            'agents'           => $agents,
            'activity'         => $activity,
        ]);
    }
}
