<?php

declare(strict_types=1);

namespace App\Models\Network\Services;

use App\Models\Network\Brokers\StatBroker;
use App\Models\Network\Entities\Stat;

class StatService
{
    /** @var array<string, string>|null memo for `current()` across one request */
    private static ?array $cached = null;

    public function __construct(
        private readonly StatBroker $broker = new StatBroker(),
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

    /**
     * Convenience accessor for templates (e.g. nav.latte) that need the
     * latest snapshot values without spinning up a controller-side fetch.
     * Returns a flat metric => float-as-string map for the headline metrics
     * surfaced site-wide. Memoized per request — multiple calls within the
     * same request hit the DB once.
     *
     * @return array<string, string>
     */
    public static function current(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $svc = new self();
        $rows = $svc->latestForMetrics([
            'value_protected_usd',
            'antibodies_active',
            'publishers_total',
            'publisher_earnings_total_usdc',
        ]);
        $out = [];
        foreach ($rows as $metric => $stat) {
            $out[$metric] = (string) $stat->value;
        }
        self::$cached = $out;
        return $out;
    }

}
