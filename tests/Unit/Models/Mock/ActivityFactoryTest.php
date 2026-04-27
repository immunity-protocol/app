<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Mock;

use Tests\Fixtures\Mock\ActivityFactory;
use Tests\TestCase;

final class ActivityFactoryTest extends TestCase
{
    public function testCombinesPublishedMirroredProtected(): void
    {
        $factory = new ActivityFactory();
        $entries = [
            ['id' => 1, 'imm_id' => 'IMM-A', 'type' => 'address', 'publisher_ens' => 'huntress.eth', 'created_at' => '2026-04-25 10:00:00+00'],
        ];
        $mirrors = [
            ['entry_id' => 1, 'chain_name' => 'sepolia', 'mirrored_at' => '2026-04-25 10:00:30+00'],
        ];
        $blocks = [
            ['entry_id' => 1, 'value_protected_usd' => '500.000000', 'agent_id' => 'huntress-01', 'occurred_at' => '2026-04-25 11:00:00+00'],
        ];

        $rows = $factory->generate($entries, $mirrors, $blocks);

        $types = array_column($rows, 'event_type');
        $this->assertContains('published', $types);
        $this->assertContains('mirrored', $types);
        $this->assertContains('protected', $types);
    }

    public function testNewestRowsLandFirst(): void
    {
        $factory = new ActivityFactory();
        $entries = [
            ['id' => 1, 'imm_id' => 'IMM-A', 'type' => 'address', 'publisher_ens' => null, 'created_at' => '2026-04-20 00:00:00+00'],
        ];
        $mirrors = [
            ['entry_id' => 1, 'chain_name' => 'sepolia', 'mirrored_at' => '2026-04-22 00:00:00+00'],
        ];
        $blocks = [
            ['entry_id' => 1, 'value_protected_usd' => '50', 'agent_id' => 'a', 'occurred_at' => '2026-04-25 00:00:00+00'],
        ];

        $rows = $factory->generate($entries, $mirrors, $blocks);

        $this->assertSame('protected', $rows[0]['event_type']);
        $this->assertSame('mirrored', $rows[1]['event_type']);
        $this->assertSame('published', $rows[2]['event_type']);
    }

    public function testCapsAtFiftyPerType(): void
    {
        $factory = new ActivityFactory();
        $entries = [];
        for ($i = 0; $i < 200; $i++) {
            $entries[] = ['id' => $i, 'imm_id' => "IMM-$i", 'type' => 'address', 'publisher_ens' => null, 'created_at' => '2026-04-25 12:00:00+00'];
        }
        $rows = $factory->generate($entries, [], []);
        $publishedCount = count(array_filter($rows, fn ($r) => $r['event_type'] === 'published'));
        $this->assertSame(50, $publishedCount);
    }
}
