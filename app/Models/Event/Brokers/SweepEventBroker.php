<?php

declare(strict_types=1);

namespace App\Models\Event\Brokers;

use App\Models\Core\Broker;
use stdClass;

class SweepEventBroker extends Broker
{
    /**
     * @return stdClass[]
     */
    public function findRecent(int $limit): array
    {
        return $this->select(
            "SELECT * FROM event.sweep_event ORDER BY occurred_at DESC LIMIT ?",
            [$limit]
        );
    }

    public function countAll(): int
    {
        return (int) $this->selectValue("SELECT count(*) FROM event.sweep_event");
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): ?int
    {
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
        $sql = "INSERT INTO event.sweep_event ($colList) VALUES ($placeholders)
                ON CONFLICT (tx_hash, log_index) DO NOTHING
                RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return $row !== null ? (int) $row->id : null;
    }
}
