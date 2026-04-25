<?php

declare(strict_types=1);

namespace App\Models\Mock;

/**
 * Generates ~60 mock agent.heartbeat rows.
 *
 *   - Roles distributed: 50% trader, 25% publisher, 20% watcher, 5% relay
 *   - last_seen within the last 5 min on a fresh seed
 *   - 60% of agents carry an ENS name
 */
final class HeartbeatFactory
{
    private const ROLE_WEIGHTS = [
        'trader'    => 50,
        'publisher' => 25,
        'watcher'   => 20,
        'relay'     => 5,
    ];

    private const STEMS = [
        'huntress', 'sentinel', 'scout', 'forta', 'metamask',
        'hawk', 'oracle', 'wraith', 'badger', 'wolf',
    ];

    public function __construct(
        private readonly int $count = 60,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function generate(): array
    {
        $now = strtotime('2026-04-25 12:00:00 UTC');
        $rows = [];
        for ($i = 0; $i < $this->count; $i++) {
            $stem = self::STEMS[$i % count(self::STEMS)];
            $agentId = sprintf('%s-%02d', $stem, $i);
            $role = Seeds::weighted(self::ROLE_WEIGHTS);
            $hasEns = Seeds::chance(0.60);
            $secondsAgo = Seeds::int(0, 5 * 60);
            $rows[] = [
                'agent_id'   => $agentId,
                'agent_ens'  => $hasEns ? sprintf('%s.eth', $stem) : null,
                'agent_role' => $role,
                'last_seen'  => gmdate('Y-m-d H:i:sP', $now - $secondsAgo),
                'peer_count' => Seeds::int(3, 24),
                'version'    => '0.1.' . Seeds::int(0, 9),
                'metadata'   => '{}',
            ];
        }
        return $rows;
    }
}
