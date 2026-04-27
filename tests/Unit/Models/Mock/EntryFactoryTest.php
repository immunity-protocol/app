<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Mock;

use Tests\Fixtures\Mock\EntryFactory;
use Tests\Fixtures\Mock\PublisherFactory;
use Tests\Fixtures\Mock\Seeds;
use Tests\TestCase;

final class EntryFactoryTest extends TestCase
{
    public function testGeneratesRequestedCount(): void
    {
        Seeds::reset(1);
        $publishers = new PublisherFactory(20);
        Seeds::reset(1);
        $factory = new EntryFactory($publishers, total: 50);
        $rows = $factory->generate();
        $this->assertCount(50, $rows);
    }

    public function testTypeDistributionApproximatesTargets(): void
    {
        Seeds::reset(1);
        $publishers = new PublisherFactory(20);
        Seeds::reset(1);
        $factory = new EntryFactory($publishers, total: 350);
        $rows = $factory->generate();

        $counts = array_count_values(array_column($rows, 'type'));
        $this->assertGreaterThan(180, $counts['address'] ?? 0);
        $this->assertLessThan(240, $counts['address'] ?? 0);
        $this->assertGreaterThan(20, $counts['semantic'] ?? 0);
        $this->assertLessThan(60, $counts['semantic'] ?? 0);
    }

    public function testStatusIsMostlyActive(): void
    {
        Seeds::reset(1);
        $publishers = new PublisherFactory(20);
        Seeds::reset(1);
        $factory = new EntryFactory($publishers, total: 350);
        $rows = $factory->generate();
        $counts = array_count_values(array_column($rows, 'status'));
        $this->assertGreaterThan(280, $counts['active'] ?? 0);
    }

    public function testImmIdsAreSequentialAndUnique(): void
    {
        Seeds::reset(1);
        $publishers = new PublisherFactory(20);
        Seeds::reset(1);
        $factory = new EntryFactory($publishers, total: 5);
        $rows = $factory->generate();
        $immIds = array_column($rows, 'imm_id');
        $this->assertSame(['IMM-2026-0001', 'IMM-2026-0002', 'IMM-2026-0003', 'IMM-2026-0004', 'IMM-2026-0005'], $immIds);
    }

    public function testSemanticEntriesCarryFlavor(): void
    {
        Seeds::reset(1);
        $publishers = new PublisherFactory(20);
        Seeds::reset(1);
        $factory = new EntryFactory($publishers, total: 200);
        $rows = $factory->generate();
        foreach ($rows as $row) {
            if ($row['type'] === 'semantic') {
                $this->assertContains($row['flavor'] ?? null, ['counterparty', 'pattern', 'injection']);
            }
        }
    }

    public function testConfidenceWithinValidRange(): void
    {
        Seeds::reset(1);
        $publishers = new PublisherFactory(20);
        Seeds::reset(1);
        $factory = new EntryFactory($publishers, total: 200);
        foreach ($factory->generate() as $row) {
            $this->assertGreaterThanOrEqual(0, $row['confidence']);
            $this->assertLessThanOrEqual(100, $row['confidence']);
        }
    }
}
