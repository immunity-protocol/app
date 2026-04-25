<?php

declare(strict_types=1);

namespace App\Models\Mock;

/**
 * Generates 7 days of 1-minute network.stat snapshots for the five tiles
 * shown on the home page.
 *
 * Each metric has a target value (matching what the templates currently
 * display), drifts gently toward it over the window, and carries a daily
 * sinusoidal modulation for visual realism.
 */
final class StatTimeSeriesFactory
{
    public const TARGETS = [
        'antibodies_active'    => 312.0,
        'agents_online'        => 1247.0,
        'cache_hits_per_hour'  => 18492.0,
        'llm_calls_saved'      => 4217.0,
        'value_protected_usd'  => 248300.0,
    ];

    public function __construct(
        private readonly int $windowDays = 7,
        private readonly int $intervalSeconds = 60,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function generate(): array
    {
        $endUnix = strtotime('2026-04-25 12:00:00 UTC');
        $startUnix = $endUnix - $this->windowDays * 24 * 3600;
        $totalSteps = (int) (($endUnix - $startUnix) / $this->intervalSeconds);

        $rows = [];
        foreach (self::TARGETS as $metric => $target) {
            $startValue = $target * 0.85;
            $dailyAmplitude = $this->dailyAmplitude($metric, $target);
            for ($step = 0; $step < $totalSteps; $step++) {
                $unix = $startUnix + $step * $this->intervalSeconds;
                $progress = $step / max(1, $totalSteps - 1);
                $base = $startValue + ($target - $startValue) * $progress;
                $hourOfDay = ((int) gmdate('G', $unix)) + ((int) gmdate('i', $unix)) / 60;
                $daily = $dailyAmplitude * sin(2 * M_PI * $hourOfDay / 24);
                $jitter = $target * 0.005 * (Seeds::float() - 0.5);
                $value = max(0.0, $base + $daily + $jitter);
                $rows[] = [
                    'metric'      => $metric,
                    'value'       => sprintf('%.6f', $value),
                    'captured_at' => gmdate('Y-m-d H:i:sP', $unix),
                ];
            }
        }
        return $rows;
    }

    private function dailyAmplitude(string $metric, float $target): float
    {
        return match ($metric) {
            'cache_hits_per_hour' => $target * 0.20,
            'agents_online'       => $target * 0.05,
            'llm_calls_saved'     => $target * 0.10,
            default               => $target * 0.01,
        };
    }
}
