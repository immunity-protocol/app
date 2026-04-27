<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Mock;

use Tests\Fixtures\Mock\MirrorFactory;
use Tests\Fixtures\Mock\Seeds;
use Tests\TestCase;

final class MirrorFactoryTest extends TestCase
{
    public function testSkipsNonAddressEntries(): void
    {
        Seeds::reset(1);
        $factory = new MirrorFactory();
        $rows = $factory->generate([
            ['id' => 1, 'type' => 'semantic', 'created_at' => '2026-04-25 12:00:00+00'],
            ['id' => 2, 'type' => 'graph',    'created_at' => '2026-04-25 12:00:00+00'],
        ]);
        $this->assertEmpty($rows);
    }

    public function testRoughlySixtyPercentOfAddressesGetMirrored(): void
    {
        Seeds::reset(1);
        $entries = [];
        for ($i = 1; $i <= 1000; $i++) {
            $entries[] = ['id' => $i, 'type' => 'address', 'created_at' => '2026-04-25 12:00:00+00'];
        }
        $factory = new MirrorFactory();
        $rows = $factory->generate($entries);

        $entryIds = array_unique(array_column($rows, 'entry_id'));
        $ratio = count($entryIds) / 1000;
        $this->assertGreaterThan(0.55, $ratio);
        $this->assertLessThan(0.65, $ratio);
    }

    public function testSepoliaIsAlwaysPresentWhenMirrored(): void
    {
        Seeds::reset(1);
        $entries = [];
        for ($i = 1; $i <= 200; $i++) {
            $entries[] = ['id' => $i, 'type' => 'address', 'created_at' => '2026-04-25 12:00:00+00'];
        }
        $factory = new MirrorFactory();
        $rows = $factory->generate($entries);

        $byEntry = [];
        foreach ($rows as $r) {
            $byEntry[$r['entry_id']][] = $r['chain_name'];
        }
        foreach ($byEntry as $chains) {
            $this->assertContains('sepolia', $chains);
        }
    }

    public function testMirroredAtLagsCreatedAtBetween5And30Seconds(): void
    {
        Seeds::reset(1);
        $entries = [['id' => 1, 'type' => 'address', 'created_at' => '2026-04-25 12:00:00+00']];
        $factory = new MirrorFactory();
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $rows = $factory->generate($entries);
            foreach ($rows as $r) {
                $delta = strtotime($r['mirrored_at']) - strtotime('2026-04-25 12:00:00+00');
                $this->assertGreaterThanOrEqual(5, $delta);
                $this->assertLessThanOrEqual(30, $delta);
            }
        }
    }
}
