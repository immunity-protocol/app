<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mock;

/**
 * Generates mock event.check_event rows and the derived event.block_event rows.
 *
 *   - 50k check_events spread over the last 7 days
 *   - 78% allow / 18% block / 4% escalate
 *   - 65% cache hits (the rest required TEE round-trip)
 *   - For each `block` decision: a corresponding block_event with a
 *     log-normal value_protected_usd; rare entries reach $20k+
 *
 * The factory does NOT write directly. The orchestrator inserts check_events
 * first to obtain ids, then inserts block_events with the matching check_event_id.
 */
final class CheckEventFactory
{
    private const DECISION_WEIGHTS = [
        'allow'    => 78,
        'block'    => 18,
        'escalate' => 4,
    ];

    private const TX_KINDS = [
        'erc20_transfer', 'erc20_approve', 'eth_transfer',
        'swap', 'message', 'multicall', 'permit',
    ];

    private const AGENT_POOL_SIZE = 50;
    private const CHAINS = [1, 8453, 11155111, 42161, 10];

    public function __construct(
        private readonly int $total = 50_000,
        private readonly int $windowDays = 7,
    ) {
    }

    /**
     * @param list<array{id: int, type: string}> $entries entries available as match targets
     * @return array{
     *     checks: list<array<string, mixed>>,
     *     blockSpecs: list<array{check_index: int, entry_id: int, value: string, occurred_at: string, agent_id: string, chain_id: int}>,
     * }
     */
    public function generate(array $entries): array
    {
        if ($entries === []) {
            return ['checks' => [], 'blockSpecs' => []];
        }

        $now = strtotime('2026-04-25 12:00:00 UTC');
        $windowSeconds = $this->windowDays * 24 * 3600;
        $agents = $this->generateAgentIds();

        $checks = [];
        $blockSpecs = [];

        for ($i = 0; $i < $this->total; $i++) {
            $occurredAtUnix = $now - Seeds::int(0, $windowSeconds);
            $occurredAt = gmdate('Y-m-d H:i:sP', $occurredAtUnix);
            $decision = Seeds::weighted(self::DECISION_WEIGHTS);
            $cacheHit = Seeds::chance(0.65);
            $teeUsed = !$cacheHit;
            $agentId = Seeds::pick($agents);
            $chainId = Seeds::pick(self::CHAINS);
            $matchedEntryId = $decision === 'allow'
                ? null
                : Seeds::pick($entries)['id'];
            $confidence = $matchedEntryId === null ? null : Seeds::int(60, 99);

            $checks[] = [
                'agent_id'           => $agentId,
                'tx_kind'            => Seeds::pick(self::TX_KINDS),
                'chain_id'           => $chainId,
                'decision'           => $decision,
                'confidence'         => $confidence,
                'matched_entry_id'   => $matchedEntryId,
                'cache_hit'          => $cacheHit ? 'true' : 'false',
                'tee_used'           => $teeUsed ? 'true' : 'false',
                'value_at_risk_usd'  => $decision === 'allow' ? null : $this->valueAtRisk(),
                'occurred_at'        => $occurredAt,
            ];

            if ($decision === 'block' && $matchedEntryId !== null) {
                $blockSpecs[] = [
                    'check_index'   => $i,
                    'entry_id'      => $matchedEntryId,
                    'value'         => $this->valueProtected(),
                    'occurred_at'   => $occurredAt,
                    'agent_id'      => $agentId,
                    'chain_id'      => $chainId,
                ];
            }
        }
        return ['checks' => $checks, 'blockSpecs' => $blockSpecs];
    }

    /**
     * Build a block_event payload from a spec + the inserted check_event id.
     *
     * @param array{entry_id: int, value: string, occurred_at: string, agent_id: string, chain_id: int} $spec
     * @return array<string, mixed>
     */
    public static function blockEventRow(int $checkEventId, array $spec): array
    {
        return [
            'check_event_id'      => $checkEventId,
            'entry_id'            => $spec['entry_id'],
            'agent_id'            => $spec['agent_id'],
            'value_protected_usd' => $spec['value'],
            'tx_hash_attempt'     => '\\x' . bin2hex(random_bytes(32)),
            'chain_id'            => $spec['chain_id'],
            'occurred_at'         => $spec['occurred_at'],
        ];
    }

    /**
     * @return list<string>
     */
    private function generateAgentIds(): array
    {
        $stems = ['huntress', 'sentinel', 'scout', 'forta', 'metamask', 'hawk', 'oracle', 'wraith'];
        $out = [];
        for ($i = 0; $i < self::AGENT_POOL_SIZE; $i++) {
            $stem = $stems[$i % count($stems)];
            $out[] = sprintf('%s-%02d', $stem, $i);
        }
        return $out;
    }

    private function valueAtRisk(): string
    {
        $v = Seeds::logNormal(800.0, 1.4);
        return sprintf('%.6f', max(10.0, min($v, 1_000_000.0)));
    }

    private function valueProtected(): string
    {
        // Median ~$300, sigma 1.6 produces a long tail with a few $20k+ entries.
        $v = Seeds::logNormal(300.0, 1.6);
        return sprintf('%.6f', max(10.0, min($v, 50_000.0)));
    }
}
