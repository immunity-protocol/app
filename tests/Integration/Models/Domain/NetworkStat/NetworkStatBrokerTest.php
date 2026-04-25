<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Domain\NetworkStat;

use App\Models\Domain\NetworkStat\NetworkStatBroker;
use Tests\IntegrationTestCase;

final class NetworkStatBrokerTest extends IntegrationTestCase
{
    private NetworkStatBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new NetworkStatBroker($this->db);
    }

    public function testLatestByMetricReturnsNewest(): void
    {
        $this->insertSnapshot('antibodies_active', '300', '2026-04-01 00:00:00+00');
        $this->insertSnapshot('antibodies_active', '312.000000', '2026-04-25 00:00:00+00');
        $this->insertSnapshot('antibodies_active', '305.000000', '2026-04-15 00:00:00+00');

        $latest = $this->broker->latestByMetric('antibodies_active');

        $this->assertNotNull($latest);
        $this->assertSame('312.000000', $latest->value);
    }

    public function testLatestByMetricReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->broker->latestByMetric('does_not_exist'));
    }

    public function testLatestForMetricsBatch(): void
    {
        $this->insertSnapshot('antibodies_active', '312.000000', '2026-04-25 12:00:00+00');
        $this->insertSnapshot('agents_online', '1247.000000', '2026-04-25 12:00:00+00');
        $this->insertSnapshot('cache_hits_per_hour', '18492', '2026-04-25 12:00:00+00');

        $latest = $this->broker->latestForMetrics([
            'antibodies_active',
            'agents_online',
            'value_protected_usd',
        ]);

        $this->assertSame('312.000000', $latest['antibodies_active']->value);
        $this->assertSame('1247.000000', $latest['agents_online']->value);
        $this->assertArrayNotHasKey('value_protected_usd', $latest);
    }

    public function testValueAtOrAfter(): void
    {
        $this->insertSnapshot('antibodies_active', '300', '2026-04-24 12:00:00+00');
        $this->insertSnapshot('antibodies_active', '305.000000', '2026-04-25 06:00:00+00');
        $this->insertSnapshot('antibodies_active', '312.000000', '2026-04-25 12:00:00+00');

        $this->assertSame('305.000000', $this->broker->valueAtOrAfter('antibodies_active', '2026-04-25T00:00:00Z'));
    }

    public function testMaxCapturedAtReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->broker->maxCapturedAt());
    }

    private function insertSnapshot(string $metric, string $value, string $capturedAt): void
    {
        $this->broker->insert([
            'metric'      => $metric,
            'value'       => $value,
            'captured_at' => $capturedAt,
        ]);
    }
}
