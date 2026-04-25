<?php

declare(strict_types=1);

namespace App\Models\Network\Brokers;

use App\Models\Core\Broker;
use stdClass;

class StatBroker extends Broker
{
    public function latestByMetric(string $metric): ?stdClass
    {
        return $this->selectOne(
            "SELECT * FROM network.stat WHERE metric = ?
             ORDER BY captured_at DESC LIMIT 1",
            [$metric]
        );
    }

    /**
     * @param string[] $metrics
     * @return array<string, stdClass> map of metric -> latest row
     */
    public function latestForMetrics(array $metrics): array
    {
        if ($metrics === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($metrics), '?'));
        $rows = $this->select(
            "SELECT DISTINCT ON (metric) *
             FROM network.stat
             WHERE metric IN ($placeholders)
             ORDER BY metric, captured_at DESC",
            $metrics
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row->metric] = $row;
        }
        return $out;
    }

    public function valueAtOrAfter(string $metric, string $sinceIso): ?string
    {
        $value = $this->selectValue(
            "SELECT value FROM network.stat
             WHERE metric = ? AND captured_at >= ?::timestamptz
             ORDER BY captured_at ASC LIMIT 1",
            [$metric, $sinceIso]
        );
        return $value === null ? null : (string) $value;
    }

    public function maxCapturedAt(): ?string
    {
        $value = $this->selectValue("SELECT max(captured_at) FROM network.stat");
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
        $sql = "INSERT INTO network.stat ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
