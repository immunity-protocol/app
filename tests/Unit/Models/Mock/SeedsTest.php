<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Mock;

use Tests\Fixtures\Mock\Seeds;
use Tests\TestCase;

final class SeedsTest extends TestCase
{
    public function testResetMakesSequenceReproducible(): void
    {
        Seeds::reset(42);
        $a = [Seeds::int(0, 1000), Seeds::int(0, 1000), Seeds::int(0, 1000)];

        Seeds::reset(42);
        $b = [Seeds::int(0, 1000), Seeds::int(0, 1000), Seeds::int(0, 1000)];

        $this->assertSame($a, $b);
    }

    public function testWeightedRespectsDistributionRoughly(): void
    {
        Seeds::reset(7);
        $counts = ['a' => 0, 'b' => 0, 'c' => 0];
        for ($i = 0; $i < 10_000; $i++) {
            $key = Seeds::weighted(['a' => 60, 'b' => 30, 'c' => 10]);
            $counts[$key]++;
        }
        $this->assertGreaterThan(5500, $counts['a']);
        $this->assertLessThan(6500, $counts['a']);
        $this->assertGreaterThan(2500, $counts['b']);
        $this->assertLessThan(3500, $counts['b']);
        $this->assertGreaterThan(700, $counts['c']);
        $this->assertLessThan(1300, $counts['c']);
    }

    public function testChanceHonorsProbabilityRoughly(): void
    {
        Seeds::reset(11);
        $hits = 0;
        for ($i = 0; $i < 10_000; $i++) {
            if (Seeds::chance(0.25)) {
                $hits++;
            }
        }
        $this->assertGreaterThan(2200, $hits);
        $this->assertLessThan(2800, $hits);
    }

    public function testPickReturnsItemFromList(): void
    {
        Seeds::reset(3);
        $list = ['x', 'y', 'z'];
        for ($i = 0; $i < 50; $i++) {
            $this->assertContains(Seeds::pick($list), $list);
        }
    }

    public function testLogNormalIsPositive(): void
    {
        Seeds::reset(99);
        for ($i = 0; $i < 100; $i++) {
            $this->assertGreaterThan(0, Seeds::logNormal(100.0, 1.0));
        }
    }
}
