<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Network\Services\StatService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class NetworkStatsController extends Controller
{
    private const METRICS = [
        'antibodies_active',
        'agents_online',
        'cache_hits_per_hour',
        'llm_calls_saved',
        'value_protected_usd',
    ];

    private StatService $stats;

    #[Get('/network/stats')]
    public function index(): Response
    {
        $this->stats ??= new StatService();
        $latest = $this->stats->latestForMetrics(self::METRICS);
        $oneHourAgo = gmdate('Y-m-d H:i:sP', strtotime('-1 hour'));
        $oneDayAgo = gmdate('Y-m-d H:i:sP', strtotime('-24 hours'));

        $tiles = [];
        foreach (self::METRICS as $metric) {
            $value = $latest[$metric]->value ?? null;
            $tiles[$metric] = [
                'value'      => $value,
                'delta_1h'   => $this->delta($metric, $oneHourAgo, $value),
                'delta_24h'  => $this->delta($metric, $oneDayAgo, $value),
            ];
        }

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
