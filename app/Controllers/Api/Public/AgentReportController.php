<?php

declare(strict_types=1);

namespace App\Controllers\Api\Public;

use App\Models\Agent\Brokers\FleetActivityBroker;
use App\Models\Agent\Brokers\FleetControlBroker;
use App\Models\Agent\Brokers\FleetMemberBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Post;

/**
 * Inbound liveness + activity reporting for the public template agent
 * (immunity-protocol/agent). Each running fleet agent POSTs a heartbeat every
 * ~60s and one activity row per observable action it takes. Both are
 * fire-and-forget on the agent side, so we keep validation strict but cheap and
 * never do anything that could hang the caller.
 *
 * Unauthenticated by design: anyone in the world can run the template agent and
 * appear on the roster — that IS the product ("an immune system run by agents").
 * The payloads are display-only (roster + activity feed); they grant no
 * authority, so the spam surface is bounded to cosmetic noise (pruned + capped).
 *
 *   POST /v1/agents/heartbeat
 *   POST /v1/agents/activity
 *
 * Contract mirrors immunity-agent/src/reporter.ts (HeartbeatPayload /
 * ActivityPayload), camelCase keys.
 */
final class AgentReportController extends Controller
{
    private const VALID_STATUS = ['allow', 'block', 'novel', 'error', 'info'];

    /**
     * Fleet pause flag — agents poll this each tick and idle while paused.
     * Public + uncacheable; flipped by the judge-gated playground control.
     */
    #[Get('/agents/control')]
    public function control(): Response
    {
        return Response::json(['paused' => (new FleetControlBroker())->isPaused()])
            ->withHeader('Cache-Control', 'no-store');
    }

    #[Post('/agents/heartbeat')]
    public function heartbeat(Request $request): Response
    {
        $b = $request->body();

        $agentId = $this->cleanId($b->get('agentId'));
        if ($agentId === null) {
            return Response::json(['error' => 'agentId required'], 400);
        }
        $role = $this->cleanShort($b->get('role'), 32);
        $displayName = $this->cleanShort($b->get('displayName'), 128);
        $version = $this->cleanShort($b->get('version'), 32);
        if ($role === null || $displayName === null || $version === null) {
            return Response::json(['error' => 'role, displayName and version required'], 400);
        }

        $wallet = $b->get('wallet');
        $wallet = is_string($wallet) && preg_match('/^0x[0-9a-fA-F]{40}$/', $wallet)
            ? strtolower($wallet)
            : null;

        $ens = $b->get('ens');
        $ens = is_string($ens) && trim($ens) !== '' ? substr(trim($ens), 0, 255) : null;

        // Adversary economics (autoimmune): budget is reported as base-units USDC
        // (6dp) string; store it as a decimal. Honest roles omit both.
        $budgetRaw = $b->get('budget');
        $budget = is_string($budgetRaw) && preg_match('/^\d{1,18}$/', $budgetRaw)
            ? sprintf('%.6f', ((int) $budgetRaw) / 1000000)
            : null;
        $bankrupt = $b->get('bankrupt') === true;

        (new FleetMemberBroker())->upsert($agentId, $role, $displayName, $wallet, $ens, $version, $budget, $bankrupt);

        return Response::json(['ok' => true], 200);
    }

    #[Post('/agents/activity')]
    public function activity(Request $request): Response
    {
        $b = $request->body();

        $agentId = $this->cleanId($b->get('agentId'));
        $role = $this->cleanShort($b->get('role'), 32);
        $displayName = $this->cleanShort($b->get('displayName'), 128);
        $actionType = $this->cleanShort($b->get('actionType'), 64);
        $actionSummary = $b->get('actionSummary');
        if ($agentId === null || $role === null || $displayName === null || $actionType === null) {
            return Response::json(['error' => 'agentId, role, displayName and actionType required'], 400);
        }
        if (!is_string($actionSummary) || trim($actionSummary) === '') {
            return Response::json(['error' => 'actionSummary required'], 400);
        }

        $status = $b->get('status');
        if (!is_string($status) || !in_array($status, self::VALID_STATUS, true)) {
            return Response::json(['error' => 'status must be one of ' . implode('|', self::VALID_STATUS)], 400);
        }

        (new FleetActivityBroker())->insert([
            'agent_id'        => $agentId,
            'role'            => $role,
            'display_name'    => $displayName,
            'action_type'     => $actionType,
            'action_summary'  => substr(trim($actionSummary), 0, 2000),
            'status'          => $status,
            'antibody_imm_id' => $this->cleanShort($b->get('antibodyImmId'), 32),
            'tx_hash'         => $this->cleanShort($b->get('txHash'), 80),
            'target'          => $this->cleanShort($b->get('target'), 80),
            'family'          => $this->cleanShort($b->get('family'), 64),
        ]);

        return Response::json(['ok' => true], 202);
    }

    private function cleanId(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $v)) {
            return null;
        }
        return $v;
    }

    private function cleanShort(mixed $v, int $max): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        return substr(trim($v), 0, $max);
    }
}
