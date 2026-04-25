<?php

declare(strict_types=1);

namespace App\Models\Event\Brokers;

use App\Models\Core\Broker;

class ActivityBroker extends Broker
{
    /**
     * Most recent activity rows. Supports keyset pagination via $beforeId.
     *
     * @return \stdClass[]
     */
    public function findRecent(int $limit, ?int $beforeId = null): array
    {
        if ($beforeId !== null) {
            return $this->select(
                "SELECT * FROM event.activity WHERE id < ? ORDER BY id DESC LIMIT ?",
                [$beforeId, $limit]
            );
        }
        return $this->select(
            "SELECT * FROM event.activity ORDER BY id DESC LIMIT ?",
            [$limit]
        );
    }

    public function countByType(string $eventType): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.activity WHERE event_type = ?::event.activity_type",
            [$eventType]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
        $sql = "INSERT INTO event.activity ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
