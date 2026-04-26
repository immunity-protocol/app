<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Network\Services\StatService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * Liveness signal for the LIVE indicator pulse on the home page.
 *
 *   - alive=true when the most recent network.stat row is fresh (<= 120s old)
 *   - age_seconds reports the gap so the client can color the pulse:
 *       green  < 30s
 *       amber  < 120s
 *       red    >= 120s or no data
 */
final class HeartbeatStatusController extends Controller
{
    private const FRESH_THRESHOLD_SECONDS = 120;

    private StatService $stats;

    #[Get('/network/heartbeat-status')]
    public function index(): Response
    {
        $this->stats ??= new StatService();
        $latest = $this->stats->maxCapturedAt();
        $ageSeconds = $latest === null ? null : (time() - strtotime($latest));
        $alive = $ageSeconds !== null && $ageSeconds <= self::FRESH_THRESHOLD_SECONDS;
        return Response::json([
            'alive'             => $alive,
            'age_seconds'       => $ageSeconds,
            'last_snapshot_at'  => $latest,
            'fresh_threshold'   => self::FRESH_THRESHOLD_SECONDS,
        ])->withHeader('Cache-Control', 'public, max-age=3, stale-while-revalidate=10');
    }
}
