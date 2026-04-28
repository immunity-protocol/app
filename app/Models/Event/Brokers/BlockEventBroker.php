<?php

declare(strict_types=1);

namespace App\Models\Event\Brokers;

use App\Models\Core\Broker;

class BlockEventBroker extends Broker
{
    /**
     * @return \stdClass[]
     */
    public function findRecent(int $limit, ?int $beforeId = null): array
    {
        if ($beforeId !== null) {
            return $this->select(
                "SELECT * FROM event.block_event WHERE id < ? ORDER BY id DESC LIMIT ?",
                [$beforeId, $limit]
            );
        }
        return $this->select(
            "SELECT * FROM event.block_event ORDER BY id DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * @return \stdClass[]
     */
    public function findRecentByEntryId(int $entryId, int $limit = 10): array
    {
        return $this->select(
            "SELECT * FROM event.block_event WHERE entry_id = ? ORDER BY id DESC LIMIT ?",
            [$entryId, $limit]
        );
    }

    public function countSince(string $sinceIso): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.block_event WHERE occurred_at >= ?::timestamptz",
            [$sinceIso]
        );
    }

    /**
     * @return \stdClass[]  rows: { agent_id, occurred_at, entry_id }
     */
    public function findRecentSince(string $sinceIso, int $limit = 200): array
    {
        return $this->select(
            "SELECT agent_id, occurred_at, entry_id
               FROM event.block_event
              WHERE occurred_at > ?::timestamptz
           ORDER BY occurred_at ASC
              LIMIT ?",
            [$sinceIso, $limit]
        );
    }

    public function sumValueProtectedAllTime(): string
    {
        return (string) $this->selectValue(
            "SELECT coalesce(sum(value_protected_usd), 0) FROM event.block_event"
        );
    }

    public function sumValueProtectedSince(string $sinceIso): string
    {
        return (string) $this->selectValue(
            "SELECT coalesce(sum(value_protected_usd), 0) FROM event.block_event
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
        $sql = "INSERT INTO event.block_event ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
