<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Event\Brokers;

use App\Models\Event\Brokers\ActivityBroker;
use Tests\IntegrationTestCase;

final class ActivityBrokerTest extends IntegrationTestCase
{
    private ActivityBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new ActivityBroker($this->db);
    }

    public function testFindRecentReturnsLatestFirst(): void
    {
        $a = $this->insertActivity('published', 'huntress.eth');
        $b = $this->insertActivity('protected', 'sentinel.eth');
        $c = $this->insertActivity('mirrored', 'relayer-01');

        $recent = $this->broker->findRecent(10);

        $this->assertCount(3, $recent);
        $this->assertSame($c, (int) $recent[0]->id);
        $this->assertSame($b, (int) $recent[1]->id);
        $this->assertSame($a, (int) $recent[2]->id);
    }

    public function testFindRecentLimits(): void
    {
        $this->insertActivity('published', 'a');
        $this->insertActivity('published', 'b');
        $this->insertActivity('published', 'c');

        $this->assertCount(2, $this->broker->findRecent(2));
    }

    public function testCountByType(): void
    {
        $this->insertActivity('published', 'a');
        $this->insertActivity('published', 'b');
        $this->insertActivity('protected', 'c');

        $this->assertSame(2, $this->broker->countByType('published'));
        $this->assertSame(1, $this->broker->countByType('protected'));
        $this->assertSame(0, $this->broker->countByType('challenged'));
    }

    private function insertActivity(string $eventType, string $actor): int
    {
        return $this->broker->insert([
            'event_type' => $eventType,
            'payload'    => '{}',
            'actor'      => $actor,
        ]);
    }
}
