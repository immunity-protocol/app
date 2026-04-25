<?php

declare(strict_types=1);

namespace App\Models\Event\Brokers;

use App\Models\Core\Broker;

class CheckEventBroker extends Broker
{
    public function countSince(string $sinceIso): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.check_event WHERE occurred_at >= ?::timestamptz",
            [$sinceIso]
        );
    }

    public function countCacheHitsSince(string $sinceIso): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.check_event
             WHERE cache_hit = true AND occurred_at >= ?::timestamptz",
            [$sinceIso]
        );
    }

    public function countTeeRoundTripsSince(string $sinceIso): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.check_event
             WHERE tee_used = true AND occurred_at >= ?::timestamptz",
            [$sinceIso]
        );
    }

    /**
     * @return array<string, int> map of decision -> count
     */
    public function countByDecisionSince(string $sinceIso): array
    {
        $rows = $this->select(
            "SELECT decision::text AS decision, count(*) AS n
             FROM event.check_event WHERE occurred_at >= ?::timestamptz
             GROUP BY decision",
            [$sinceIso]
        );
        $out = ['allow' => 0, 'block' => 0, 'escalate' => 0];
        foreach ($rows as $r) {
            $out[$r->decision] = (int) $r->n;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
        $sql = "INSERT INTO event.check_event ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
