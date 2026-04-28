<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Demo\Brokers\CommandBroker;
use App\Models\Demo\Brokers\FleetStateBroker;
use App\Models\Demo\Brokers\HeartbeatBroker;
use App\Models\Event\Brokers\CheckEventBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Middleware;
use Zephyrus\Routing\Attribute\Post;

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
    private CommandBroker $commands;

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

    /**
     * Poll a command's status. Cards POST to enqueue and then GET this until
     * `result_status` is "completed" or "failed".
     */
    #[Get('/playground/commands/{id}')]
    public function commandStatus(string $id): Response
    {
        if (!ctype_digit($id)) {
            return Response::json(['error' => 'id must be a positive integer'], 400);
        }
        $this->commands ??= new CommandBroker();
        $row = $this->commands->findById((int) $id);
        if ($row === null) {
            return Response::json(['error' => 'not found'], 404);
        }
        return Response::json([
            'id'              => (int) $row->id,
            'agent_id'        => $row->agent_id,
            'command_type'    => $row->command_type,
            'scheduled_at'    => $row->scheduled_at,
            'picked_up_at'    => $row->picked_up_at,
            'executed_at'     => $row->executed_at,
            'result_status'   => $row->result_status,
            'result_detail'   => $this->decodeJsonb($row->result_detail),
            'payload'         => $this->decodeJsonb($row->payload),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Card 1 — Test an address.
     * Picks a random online trader, enqueues a `check_only` command targeting
     * the supplied address. Caller polls /commands/{id} for the result.
     */
    #[Post('/playground/check-address')]
    public function checkAddress(Request $request): Response
    {
        $body = $request->body();
        $address = (string) $body->get('address', '');
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            return Response::json(['error' => 'address must be 0x-prefixed 20-byte hex'], 400);
        }
        $this->heartbeats ??= new HeartbeatBroker();
        $agentId = $this->heartbeats->pickRandomOnline('trader');
        if ($agentId === null) {
            return Response::json(['error' => 'no online traders'], 503);
        }
        $this->commands ??= new CommandBroker();
        $commandId = $this->commands->enqueue($agentId, 'check_only', [
            'address'    => strtolower($address),
            'amount_usd' => 100,
        ]);
        return Response::json([
            'command_id' => $commandId,
            'agent_id'   => $agentId,
        ], 202);
    }

    /**
     * Card 2 — Publish a threat. Enqueues an `external_threat_alert` command
     * for watcher-1, which mints a fresh ADDRESS antibody on chain.
     */
    #[Post('/playground/publish-threat')]
    public function publishThreat(Request $request): Response
    {
        $body = $request->body();
        $address = (string) $body->get('address', '');
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            return Response::json(['error' => 'address must be 0x-prefixed 20-byte hex'], 400);
        }
        $severity = (int) $body->get('severity', 80);
        if ($severity < 0 || $severity > 100) {
            return Response::json(['error' => 'severity must be 0..100'], 400);
        }
        $reasoning = trim((string) $body->get('reasoning', ''));
        if ($reasoning === '') {
            return Response::json(['error' => 'reasoning is required'], 400);
        }
        $verdict = $body->get('verdict') === 'SUSPICIOUS' ? 'SUSPICIOUS' : 'MALICIOUS';

        $this->commands ??= new CommandBroker();
        $commandId = $this->commands->enqueue('watcher-1', 'external_threat_alert', [
            'address'   => strtolower($address),
            'severity'  => $severity,
            'verdict'   => $verdict,
            'reasoning' => $reasoning,
            'source'    => 'playground',
        ]);
        return Response::json(['command_id' => $commandId, 'agent_id' => 'watcher-1'], 202);
    }

    /**
     * Card 3 — Send a malicious payload. Picks a random online trader and
     * runs `check_only` with the supplied text in the conversation context.
     * SemanticMatcher catches known patterns; the TEE evaluates novel ones.
     */
    #[Post('/playground/check-payload')]
    public function checkPayload(Request $request): Response
    {
        $body = $request->body();
        $payloadText = trim((string) $body->get('payload_text', ''));
        if ($payloadText === '') {
            return Response::json(['error' => 'payload_text is required'], 400);
        }
        $this->heartbeats ??= new HeartbeatBroker();
        $agentId = $this->heartbeats->pickRandomOnline('trader');
        if ($agentId === null) {
            return Response::json(['error' => 'no online traders'], 503);
        }
        $this->commands ??= new CommandBroker();
        $commandId = $this->commands->enqueue($agentId, 'check_only', [
            'payload_text' => $payloadText,
            'amount_usd'   => 100,
        ]);
        return Response::json(['command_id' => $commandId, 'agent_id' => $agentId], 202);
    }

    /**
     * Card 4 — Trigger an attack. Operator picks the wolf, target address,
     * amount, and method. The wolf agent runs an `attack` command which
     * builds the synthesised tx and calls check().
     */
    #[Post('/playground/trigger-attack')]
    public function triggerAttack(Request $request): Response
    {
        $body = $request->body();
        $wolf = (string) $body->get('wolf_id', '');
        if (!preg_match('/^wolf-[1-3]$/', $wolf)) {
            return Response::json(['error' => 'wolf_id must be wolf-1, wolf-2, or wolf-3'], 400);
        }
        $target = (string) $body->get('target', '');
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $target)) {
            return Response::json(['error' => 'target must be 0x-prefixed 20-byte hex'], 400);
        }
        $amountUsd = (int) $body->get('amount_usd', 5000);
        if ($amountUsd < 1 || $amountUsd > 1_000_000) {
            return Response::json(['error' => 'amount_usd must be 1..1000000'], 400);
        }
        $method = (string) $body->get('method', 'drain');
        if (!in_array($method, ['drain', 'approve', 'honeypot-swap', 'prompt-inject'], true)) {
            return Response::json(['error' => 'unknown method'], 400);
        }

        $this->commands ??= new CommandBroker();
        $commandId = $this->commands->enqueue($wolf, 'attack', [
            'method'     => $method,
            'target'     => strtolower($target),
            'amount_usd' => $amountUsd,
        ]);
        return Response::json(['command_id' => $commandId, 'agent_id' => $wolf], 202);
    }

    /**
     * Card 5 helper — list recent ADDRESS antibodies for the dropdown.
     */
    #[Get('/playground/recent-address-antibodies')]
    public function recentAddressAntibodies(): Response
    {
        $this->commands ??= new CommandBroker();
        return Response::json(['items' => $this->commands->recentAddressAntibodies(20)])
            ->withHeader('Cache-Control', 'public, max-age=15');
    }

    /**
     * Card 5 — Cache replay. Looks up the antibody's target address and fires
     * an `attack` against it from a random wolf. The AddressMatcher hits in
     * microseconds so the result panel emphasises `source: cache`.
     */
    #[Post('/playground/cache-replay')]
    public function cacheReplay(Request $request): Response
    {
        $body = $request->body();
        $immId = (string) $body->get('imm_id', '');
        if (!preg_match('/^IMM-\d{4}-\d{4}$/', $immId)) {
            return Response::json(['error' => 'imm_id must look like IMM-YYYY-NNNN'], 400);
        }
        $this->commands ??= new CommandBroker();
        $target = $this->commands->findAddressByImmId($immId);
        if ($target === null) {
            return Response::json(['error' => 'antibody not found or not an ADDRESS type with a target'], 404);
        }
        $this->heartbeats ??= new HeartbeatBroker();
        $wolf = $this->heartbeats->pickRandomOnline('wolf') ?? 'wolf-1';
        $commandId = $this->commands->enqueue($wolf, 'attack', [
            'method'     => 'drain',
            'target'     => $target,
            'amount_usd' => 4500,
            'context'    => "Replay of $immId — checking cache hit speed.",
        ]);
        return Response::json(['command_id' => $commandId, 'agent_id' => $wolf, 'replayed_imm_id' => $immId, 'target' => $target], 202);
    }

    private function decodeJsonb(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded;
        }
        return $value;
    }
}
