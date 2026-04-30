<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Antibody\Services\EntryService;
use App\Models\Demo\Brokers\HeartbeatBroker;
use App\Models\Event\Brokers\BlockEventBroker;
use App\Models\Network\Services\StatService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class HomeController extends Controller
{
    /**
     * Snapshot metrics surfaced on the landing-page tiles. Fetched
     * server-side at render time so the page is fully static — no AJAX
     * poll, no "LIVE" indicator. The dashboard route is where the live
     * polling lives; the landing page is a marketing surface that should
     * read the same on every refresh until someone reloads.
     */
    private const TILE_METRICS = [
        'antibodies_active',
        'llm_calls_saved',
        'value_protected_usd',
        'publishers_total',
        'publisher_earnings_total_usdc',
    ];

    #[Get('/')]
    public function index(): Response
    {
        // Top antibodies on the landing - the "what's hot" view (highest
        // cache hits = most matched threats). Dashboard shows latest-by-id
        // for the live "what just happened" view.
        $topAntibodies = (new EntryService())->findTopByCacheHits(10);

        $stats = (new StatService())->latestForMetrics(self::TILE_METRICS);
        $tiles = [];
        foreach (self::TILE_METRICS as $m) {
            $tiles[$m] = isset($stats[$m]) ? (float) $stats[$m]->value : 0.0;
        }
        // agents_online is a live demo metric, not a snapshot — read directly.
        $tiles['agents_online'] = (new HeartbeatBroker())->countOnline();

        // 1h deltas for the Antibodies-active and Value-protected tiles.
        // Computed server-side so the landing page can stay AJAX-free; same
        // shape as the dashboard's "+X in 1h" sub-stat for visual parity.
        $oneHourAgo = gmdate('Y-m-d\TH:i:s\Z', strtotime('-1 hour'));
        $tiles['antibodies_published_1h'] = (new EntryBroker())->countCreatedSince($oneHourAgo);
        $tiles['value_protected_1h'] = (new BlockEventBroker())->sumValueProtectedSince($oneHourAgo);

        return $this->render('home', [
            'topAntibodies' => $topAntibodies,
            'tiles'         => $tiles,
        ]);
    }
}
