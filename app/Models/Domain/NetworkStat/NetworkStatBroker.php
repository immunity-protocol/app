<?php

declare(strict_types=1);

namespace App\Models\Domain\NetworkStat;

use Zephyrus\Data\Broker;

final class NetworkStatBroker extends Broker
{
    public function latestByMetric(string $metric): ?NetworkStat
    {
        $row = $this->selectOne(
            "SELECT * FROM network_stats WHERE metric = ?
             ORDER BY captured_at DESC LIMIT 1",
            [$metric]
        );
        return $row === null ? null : NetworkStat::fromRow($row);
    }

    /**
     * Most recent value for each metric in $metrics. Missing metrics are
     * absent from the result map.
     *
     * @param string[] $metrics
     * @return array<string, NetworkStat>
     */
    public function latestForMetrics(array $metrics): array
    {
        if ($metrics === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($metrics), '?'));
        $rows = $this->select(
            "SELECT DISTINCT ON (metric) *
             FROM network_stats
             WHERE metric IN ($placeholders)
             ORDER BY metric, captured_at DESC",
            $metrics
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row->metric] = NetworkStat::fromRow($row);
        }
        return $out;
    }

    /**
     * Earliest value for $metric whose captured_at >= $sinceIso. Used to compute
     * deltas between "now" and the start of a window.
     */
    public function valueAtOrAfter(string $metric, string $sinceIso): ?string
    {
        $value = $this->selectValue(
            "SELECT value FROM network_stats
             WHERE metric = ? AND captured_at >= ?::timestamptz
             ORDER BY captured_at ASC LIMIT 1",
            [$metric, $sinceIso]
        );
        return $value === null ? null : (string) $value;
    }

    public function maxCapturedAt(): ?string
    {
        $value = $this->selectValue("SELECT max(captured_at) FROM network_stats");
        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
        $sql = "INSERT INTO network_stats ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
