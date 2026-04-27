<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Mock;

use Tests\Fixtures\Mock\Seeds;
use Tests\Fixtures\Mock\StatTimeSeriesFactory;
use Tests\TestCase;

final class StatTimeSeriesFactoryTest extends TestCase
{
    public function testGeneratesOnePointPerMetricPerInterval(): void
    {
        Seeds::reset(1);
        $factory = new StatTimeSeriesFactory(windowDays: 1, intervalSeconds: 600);
        $rows = $factory->generate();

        $expected = (24 * 3600 / 600) * count(StatTimeSeriesFactory::TARGETS);
        $this->assertSame($expected, count($rows));
    }

    public function testEachTargetMetricIsRepresented(): void
    {
        Seeds::reset(1);
        $rows = (new StatTimeSeriesFactory(windowDays: 1, intervalSeconds: 3600))->generate();
        $metrics = array_unique(array_column($rows, 'metric'));
        sort($metrics);
        $expected = array_keys(StatTimeSeriesFactory::TARGETS);
        sort($expected);
        $this->assertSame($expected, $metrics);
    }

    public function testFinalSnapshotIsCloseToTarget(): void
    {
        Seeds::reset(1);
        $factory = new StatTimeSeriesFactory(windowDays: 7, intervalSeconds: 600);
        $rows = $factory->generate();

        $latestPerMetric = [];
        foreach ($rows as $r) {
            $key = $r['metric'];
            if (!isset($latestPerMetric[$key]) || $r['captured_at'] > $latestPerMetric[$key]['captured_at']) {
                $latestPerMetric[$key] = $r;
            }
        }

        foreach (StatTimeSeriesFactory::TARGETS as $metric => $target) {
            $value = (float) $latestPerMetric[$metric]['value'];
            $this->assertGreaterThan($target * 0.7, $value);
            $this->assertLessThan($target * 1.3, $value);
        }
    }
}
