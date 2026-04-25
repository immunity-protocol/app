<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Event\Brokers;

use App\Models\Event\Brokers\CheckEventBroker;
use Tests\IntegrationTestCase;

final class CheckEventBrokerTest extends IntegrationTestCase
{
    private CheckEventBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new CheckEventBroker($this->db);
    }

    public function testCountsAndDecisionBuckets(): void
    {
        $this->insertEvent(decision: 'allow', cacheHit: true);
        $this->insertEvent(decision: 'allow', cacheHit: true);
        $this->insertEvent(decision: 'block', cacheHit: false);
        $this->insertEvent(decision: 'block', cacheHit: true);
        $this->insertEvent(decision: 'escalate', cacheHit: false, teeUsed: true);

        $sinceIso = '2000-01-01T00:00:00Z';
        $this->assertSame(5, $this->broker->countSince($sinceIso));
        $this->assertSame(3, $this->broker->countCacheHitsSince($sinceIso));
        $this->assertSame(1, $this->broker->countTeeRoundTripsSince($sinceIso));

        $byDecision = $this->broker->countByDecisionSince($sinceIso);
        $this->assertSame(2, $byDecision['allow']);
        $this->assertSame(2, $byDecision['block']);
        $this->assertSame(1, $byDecision['escalate']);
    }

    public function testCountSinceFiltersByTime(): void
    {
        $this->insertEvent(occurredAt: '2025-01-01 00:00:00+00');
        $this->insertEvent(occurredAt: '2026-01-01 00:00:00+00');
        $this->insertEvent(occurredAt: '2026-04-01 00:00:00+00');

        $this->assertSame(2, $this->broker->countSince('2026-01-01T00:00:00Z'));
        $this->assertSame(1, $this->broker->countSince('2026-02-01T00:00:00Z'));
    }

    private function insertEvent(
        string $decision = 'allow',
        bool $cacheHit = true,
        bool $teeUsed = false,
        string $occurredAt = '2026-04-25 12:00:00+00',
    ): int {
        return $this->broker->insert([
            'agent_id'    => 'huntress-01',
            'tx_kind'     => 'erc20_transfer',
            'chain_id'    => 1,
            'decision'    => $decision,
            'cache_hit'   => $cacheHit ? 'true' : 'false',
            'tee_used'    => $teeUsed ? 'true' : 'false',
            'occurred_at' => $occurredAt,
        ]);
    }
}
