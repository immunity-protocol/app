<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Mock;

use App\Models\Mock\PublisherFactory;
use App\Models\Mock\Seeds;
use Tests\TestCase;

final class PublisherFactoryTest extends TestCase
{
    public function testGeneratesRequestedCount(): void
    {
        Seeds::reset(1);
        $factory = new PublisherFactory(80);
        $this->assertCount(80, $factory->all());
    }

    public function testEachPublisherHasTwentyByteAddress(): void
    {
        Seeds::reset(1);
        $factory = new PublisherFactory(20);
        foreach ($factory->all() as $p) {
            $this->assertSame(20, strlen($p['address']));
        }
    }

    public function testRoughlySixtyPercentHaveEns(): void
    {
        Seeds::reset(1);
        $factory = new PublisherFactory(80);
        $withEns = array_filter($factory->all(), fn ($p) => $p['ens'] !== null);
        $ratio = count($withEns) / 80;
        $this->assertGreaterThanOrEqual(0.55, $ratio);
        $this->assertLessThanOrEqual(0.65, $ratio);
    }

    public function testEnsNamesAreUnique(): void
    {
        Seeds::reset(1);
        $factory = new PublisherFactory(80);
        $names = array_filter(array_column($factory->all(), 'ens'));
        $this->assertCount(count($names), array_unique($names));
    }

    public function testReproducibleWithSameSeed(): void
    {
        Seeds::reset(42);
        $a = (new PublisherFactory(20))->all();

        Seeds::reset(42);
        $b = (new PublisherFactory(20))->all();

        $this->assertSame(
            array_map(fn ($p) => bin2hex($p['address']) . '|' . ($p['ens'] ?? ''), $a),
            array_map(fn ($p) => bin2hex($p['address']) . '|' . ($p['ens'] ?? ''), $b),
        );
    }

    public function testPickRandomReturnsPublisherFromList(): void
    {
        Seeds::reset(7);
        $factory = new PublisherFactory(10);
        $allHex = array_map(fn ($p) => bin2hex($p['address']), $factory->all());
        for ($i = 0; $i < 30; $i++) {
            $picked = bin2hex($factory->pickRandom()['address']);
            $this->assertContains($picked, $allHex);
        }
    }
}
