<?php

declare(strict_types=1);

namespace App\Models\Domain\BlockEvent;

use Zephyrus\Data\Broker;

final class BlockEventBroker extends Broker
{
    /**
     * @return BlockEvent[]
     */
    public function findRecent(int $limit, ?int $beforeId = null): array
    {
        if ($beforeId !== null) {
            $rows = $this->select(
                "SELECT * FROM block_events WHERE id < ? ORDER BY id DESC LIMIT ?",
                [$beforeId, $limit]
            );
        } else {
            $rows = $this->select(
                "SELECT * FROM block_events ORDER BY id DESC LIMIT ?",
                [$limit]
            );
        }
        return array_map(BlockEvent::fromRow(...), $rows);
    }

    public function countSince(string $sinceIso): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM block_events WHERE occurred_at >= ?::timestamptz",
            [$sinceIso]
        );
    }

    public function sumValueProtectedAllTime(): string
    {
        return (string) $this->selectValue(
            "SELECT coalesce(sum(value_protected_usd), 0) FROM block_events"
        );
    }

    public function sumValueProtectedSince(string $sinceIso): string
    {
        return (string) $this->selectValue(
            "SELECT coalesce(sum(value_protected_usd), 0) FROM block_events
             WHERE occurred_at >= ?::timestamptz",
            [$sinceIso]
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
        $sql = "INSERT INTO block_events ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
