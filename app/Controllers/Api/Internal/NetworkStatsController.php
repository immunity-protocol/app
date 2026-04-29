<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Demo\Brokers\HeartbeatBroker;
use App\Models\Network\Services\StatService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class NetworkStatsController extends Controller
{
    /**
     * Network-wide cumulative metrics live in `network.stat` (snapshot
     * table populated every 60s by the indexer's StatRefresher). Demo-
     * bounded point-in-time metrics like `agents_online` are read live
     * from `demo.*` instead — cheaper than a snapshot for a single
     * `count(*)`, and immune to the failure mode where a stale indexer
     * leaves the tile frozen at a stale value.
     */
    private const SNAPSHOT_METRICS = [
        'antibodies_active',
        'cache_hits_per_hour',
        'llm_calls_saved',
        'value_protected_usd',
        'publishers_total',
        'publisher_earnings_total_usdc',
    ];

    private StatService $stats;

    #[Get('/network/stats')]
    public function index(): Response
    {
        $this->stats ??= new StatService();
        $latest = $this->stats->latestForMetrics(self::SNAPSHOT_METRICS);
        $oneHourAgo = gmdate('Y-m-d H:i:sP', strtotime('-1 hour'));
        $oneDayAgo = gmdate('Y-m-d H:i:sP', strtotime('-24 hours'));

        $tiles = [];
        foreach (self::SNAPSHOT_METRICS as $metric) {
            $value = $latest[$metric]->value ?? null;
            $tiles[$metric] = [
                'value'      => $value,
                'delta_1h'   => $this->delta($metric, $oneHourAgo, $value),
                'delta_24h'  => $this->delta($metric, $oneDayAgo, $value),
            ];
        }

        // Live demo metric — always fresh, no indexer dependency.
        $tiles['agents_online'] = [
            'value'     => (string) (new HeartbeatBroker())->countOnline(),
            'delta_1h'  => null,
            'delta_24h' => null,
        ];

        $maxCapturedAt = $this->stats->maxCapturedAt();
        $payload = [
            'tiles'             => $tiles,
            'last_snapshot_at'  => $maxCapturedAt,
            'age_seconds'       => $maxCapturedAt === null
                ? null
                : (time() - strtotime($maxCapturedAt)),
        ];

        return Response::json($payload)
            ->withHeader('Cache-Control', 'public, max-age=5, stale-while-revalidate=10');
    }

    private function delta(string $metric, string $sinceIso, ?string $current): ?string
    {
        if ($current === null) {
            return null;
        }
        $start = $this->stats->valueAtOrAfter($metric, $sinceIso);
        if ($start === null) {
            return null;
        }
        return sprintf('%.6f', (float) $current - (float) $start);
    }
}
