<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Mock;

use Tests\Fixtures\Mock\CheckEventFactory;
use Tests\Fixtures\Mock\Seeds;
use Tests\TestCase;

final class CheckEventFactoryTest extends TestCase
{
    public function testGeneratesRequestedCount(): void
    {
        Seeds::reset(1);
        $factory = new CheckEventFactory(total: 1000);
        $entries = [['id' => 1, 'type' => 'address'], ['id' => 2, 'type' => 'address']];
        $out = $factory->generate($entries);
        $this->assertCount(1000, $out['checks']);
    }

    public function testCacheHitRateApproximates65Percent(): void
    {
        Seeds::reset(1);
        $factory = new CheckEventFactory(total: 5000);
        $entries = [['id' => 1, 'type' => 'address']];
        $out = $factory->generate($entries);
        $hits = array_sum(array_map(fn ($c) => $c['cache_hit'] === 'true' ? 1 : 0, $out['checks']));
        $ratio = $hits / 5000;
        $this->assertGreaterThan(0.62, $ratio);
        $this->assertLessThan(0.68, $ratio);
    }

    public function testDecisionDistributionApproximatesTargets(): void
    {
        Seeds::reset(1);
        $factory = new CheckEventFactory(total: 5000);
        $entries = [['id' => 1, 'type' => 'address']];
        $out = $factory->generate($entries);
        $counts = array_count_values(array_column($out['checks'], 'decision'));
        $this->assertGreaterThan(3700, $counts['allow']);
        $this->assertLessThan(4100, $counts['allow']);
        $this->assertGreaterThan(800, $counts['block']);
        $this->assertLessThan(1000, $counts['block']);
        $this->assertGreaterThan(150, $counts['escalate']);
        $this->assertLessThan(250, $counts['escalate']);
    }

    public function testBlockSpecsCountMatchesBlockDecisions(): void
    {
        Seeds::reset(1);
        $factory = new CheckEventFactory(total: 2000);
        $entries = [['id' => 1, 'type' => 'address']];
        $out = $factory->generate($entries);
        $blockChecks = array_filter($out['checks'], fn ($c) => $c['decision'] === 'block');
        $this->assertCount(count($blockChecks), $out['blockSpecs']);
    }

    public function testAllowEventsHaveNoMatch(): void
    {
        Seeds::reset(1);
        $factory = new CheckEventFactory(total: 200);
        $entries = [['id' => 1, 'type' => 'address']];
        $out = $factory->generate($entries);
        foreach ($out['checks'] as $c) {
            if ($c['decision'] === 'allow') {
                $this->assertNull($c['matched_entry_id']);
            } else {
                $this->assertSame(1, $c['matched_entry_id']);
            }
        }
    }

    public function testBlockEventRowComposesCheckEventId(): void
    {
        $spec = [
            'entry_id'    => 7,
            'value'       => '123.456000',
            'occurred_at' => '2026-04-25 12:00:00+00',
            'agent_id'    => 'huntress-01',
            'chain_id'    => 8453,
        ];
        $row = CheckEventFactory::blockEventRow(99, $spec);
        $this->assertSame(99, $row['check_event_id']);
        $this->assertSame(7, $row['entry_id']);
        $this->assertSame('123.456000', $row['value_protected_usd']);
        $this->assertSame(8453, $row['chain_id']);
    }
}
