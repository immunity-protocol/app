<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Mock;

use App\Models\Mock\HeartbeatFactory;
use App\Models\Mock\Seeds;
use Tests\TestCase;

final class HeartbeatFactoryTest extends TestCase
{
    public function testGeneratesRequestedCount(): void
    {
        Seeds::reset(1);
        $rows = (new HeartbeatFactory(60))->generate();
        $this->assertCount(60, $rows);
    }

    public function testAgentIdsAreUnique(): void
    {
        Seeds::reset(1);
        $rows = (new HeartbeatFactory(60))->generate();
        $ids = array_column($rows, 'agent_id');
        $this->assertCount(60, array_unique($ids));
    }

    public function testRolesAreFromAllowedSet(): void
    {
        Seeds::reset(1);
        $rows = (new HeartbeatFactory(60))->generate();
        $allowed = ['trader', 'publisher', 'watcher', 'relay'];
        foreach ($rows as $r) {
            $this->assertContains($r['agent_role'], $allowed);
        }
    }

    public function testLastSeenIsWithinFiveMinutes(): void
    {
        Seeds::reset(1);
        $rows = (new HeartbeatFactory(60))->generate();
        $now = strtotime('2026-04-25 12:00:00 UTC');
        foreach ($rows as $r) {
            $delta = $now - strtotime($r['last_seen']);
            $this->assertGreaterThanOrEqual(0, $delta);
            $this->assertLessThanOrEqual(5 * 60, $delta);
        }
    }
}
