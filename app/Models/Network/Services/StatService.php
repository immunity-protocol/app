<?php

declare(strict_types=1);

namespace App\Models\Network\Services;

use App\Models\Network\Brokers\StatBroker;
use App\Models\Network\Entities\Stat;

readonly class StatService
{
    public function __construct(
        private StatBroker $broker = new StatBroker(),
    ) {
    }

    public function latestByMetric(string $metric): ?Stat
    {
        return Stat::build($this->broker->latestByMetric($metric));
    }

    /**
     * @param string[] $metrics
     * @return array<string, Stat>
     */
    public function latestForMetrics(array $metrics): array
    {
        $rows = $this->broker->latestForMetrics($metrics);
        $out = [];
        foreach ($rows as $metric => $row) {
            $out[$metric] = Stat::build($row);
        }
        return $out;
    }

    public function valueAtOrAfter(string $metric, string $sinceIso): ?string
    {
        return $this->broker->valueAtOrAfter($metric, $sinceIso);
    }

    public function maxCapturedAt(): ?string
    {
        return $this->broker->maxCapturedAt();
    }
}
