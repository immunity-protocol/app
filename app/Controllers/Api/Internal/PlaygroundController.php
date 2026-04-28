<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Demo\Brokers\FleetStateBroker;
use App\Models\Demo\Brokers\HeartbeatBroker;
use App\Models\Event\Brokers\CheckEventBroker;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Middleware;

/**
 * Judge-tier endpoints powering the /playground page (Sections 1 + 2).
 *
 * Same-origin under the WEB host (no CORS). Gated by the `playground` named
 * middleware, which checks the session for the judge or admin tier set by
 * Web\PlaygroundController::login().
 */
#[Middleware('playground')]
final class PlaygroundController extends Controller
{
    private HeartbeatBroker $heartbeats;
    private FleetStateBroker $fleetState;
    private CheckEventBroker $checkEvents;
    private EntryBroker $entries;

    /**
     * Live status snapshot consumed by Section 1's polling JS.
     */
    #[Get('/playground/status')]
    public function status(): Response
    {
        $this->heartbeats  ??= new HeartbeatBroker();
        $this->fleetState  ??= new FleetStateBroker();
        $this->checkEvents ??= new CheckEventBroker();
        $this->entries     ??= new EntryBroker();

        $byRole = $this->heartbeats->countOnlineByRole();
        $online = array_sum($byRole);
        $total  = $this->heartbeats->countTotal();
        $state  = $this->fleetState->get();

        $fiveMinutesAgo = gmdate('Y-m-d H:i:sP', strtotime('-5 minutes'));
        $oneHourAgo     = gmdate('Y-m-d H:i:sP', strtotime('-1 hour'));

        $eventsLast5min = $this->checkEvents->countSince($fiveMinutesAgo);
        $eventsPerMin   = $eventsLast5min === 0 ? 0.0 : round($eventsLast5min / 5, 2);

        $blocksLastHour = $this->countBlocksSince($oneHourAgo);
        $antibodyTotal  = $this->countActiveAntibodies();

        return Response::json([
            'agents' => [
                'online'   => $online,
                'total'    => $total,
                'by_role'  => $byRole,
            ],
            'ambient' => [
                'paused'    => (bool) $state->ambient_paused,
                'paused_at' => $state->paused_at,
            ],
            'activity' => [
                'events_per_min_5min' => $eventsPerMin,
                'blocks_last_hour'    => $blocksLastHour,
            ],
            'registry' => [
                'antibodies_active' => $antibodyTotal,
            ],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function countBlocksSince(string $sinceIso): int
    {
        $byDecision = $this->checkEvents->countByDecisionSince($sinceIso);
        return $byDecision['block'] ?? 0;
    }

    private function countActiveAntibodies(): int
    {
        return (int) $this->entries->countActive();
    }
}
