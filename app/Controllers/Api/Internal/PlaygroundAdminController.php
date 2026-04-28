<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Demo\Brokers\CommandBroker;
use App\Models\Demo\Brokers\FleetStateBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Middleware;
use Zephyrus\Routing\Attribute\Post;

/**
 * Admin-tier endpoints for /playground Section 3 + Card 8.
 *
 * Gated by the `admin` named middleware. The judge tier cannot reach these.
 * Destructive operations (kill containers, manual queue insertion) live here.
 */
#[Middleware('admin')]
final class PlaygroundAdminController extends Controller
{
    private CommandBroker $commands;
    private FleetStateBroker $fleetState;

    /**
     * Card 8 (Resilience test). Hard-kills N random trader containers.
     *
     * Requires the web container to have access to the docker socket
     * (mounted at /var/run/docker.sock). When that's not available, returns
     * a 501 with the command the operator can run manually instead.
     */
    #[Post('/playground/admin/resilience-test')]
    public function resilienceTest(Request $request): Response
    {
        $body = $request->body();
        $count = (int) $body->get('count', 5);
        if ($count < 1 || $count > 10) {
            return Response::json(['error' => 'count must be 1..10'], 400);
        }

        $cmd = sprintf(
            "docker ps --filter 'name=immunity-demo-trader-' --format '{{.Names}}' | shuf | head -%d",
            $count,
        );
        $names = @shell_exec($cmd . ' 2>&1');
        if ($names === null || $names === false) {
            return Response::json([
                'error'      => 'shell_exec unavailable in this container',
                'manual_cmd' => "bash scenarios/05-resilience-kill-nodes.sh $count",
            ], 501);
        }
        $names = trim((string) $names);
        if ($names === '') {
            return Response::json(['error' => 'no trader containers running'], 503);
        }
        $list = preg_split('/\R/', $names) ?: [];
        $killed = [];
        foreach ($list as $name) {
            $name = trim($name);
            if ($name === '') continue;
            @shell_exec('docker kill ' . escapeshellarg($name) . ' 2>&1');
            $killed[] = $name;
        }
        return Response::json([
            'killed_count' => count($killed),
            'killed'       => $killed,
            'note'         => 'Restart policy unless-stopped will bring containers back within ~60s.',
        ]);
    }
}
