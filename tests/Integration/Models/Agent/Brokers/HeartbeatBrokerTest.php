<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Agent\Brokers;

use App\Models\Agent\Brokers\HeartbeatBroker;
use Tests\IntegrationTestCase;

final class HeartbeatBrokerTest extends IntegrationTestCase
{
    private HeartbeatBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new HeartbeatBroker($this->db);
    }

    public function testUpsertInsertsThenUpdates(): void
    {
        $this->broker->upsert($this->fixture('huntress-01', peer: 5));
        $this->broker->upsert($this->fixture('huntress-01', peer: 12));

        $all = $this->broker->findAll();
        $this->assertCount(1, $all);
        $this->assertSame('huntress-01', $all[0]->agent_id);
        $this->assertSame(12, (int) $all[0]->peer_count);
    }

    public function testCountOnlineFiltersStale(): void
    {
        $this->broker->upsert($this->fixture('fresh-01'));
        $this->broker->upsert($this->fixture('stale-01'));
        $this->db->query(
            "UPDATE agent.heartbeat SET last_seen = now() - interval '1 hour' WHERE agent_id = ?",
            ['stale-01']
        );

        $this->assertSame(1, $this->broker->countOnline(300));
        $this->assertSame(2, $this->broker->countOnline(7200));
    }

    public function testCountByRoleGroups(): void
    {
        $this->broker->upsert($this->fixture('a', role: 'trader'));
        $this->broker->upsert($this->fixture('b', role: 'trader'));
        $this->broker->upsert($this->fixture('c', role: 'publisher'));
        $this->broker->upsert($this->fixture('d', role: 'watcher'));

        $counts = $this->broker->countByRole();

        $this->assertSame(2, $counts['trader']);
        $this->assertSame(1, $counts['publisher']);
        $this->assertSame(1, $counts['watcher']);
    }

    public function testMaxLastSeenReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->broker->maxLastSeen());
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $agentId, string $role = 'trader', int $peer = 0): array
    {
        return [
            'agent_id'    => $agentId,
            'agent_role'  => $role,
            'peer_count'  => $peer,
            'version'     => '0.1.0',
        ];
    }
}
