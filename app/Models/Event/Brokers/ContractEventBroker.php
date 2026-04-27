<?php

declare(strict_types=1);

namespace App\Models\Event\Brokers;

use App\Models\Core\Broker;
use stdClass;

class ContractEventBroker extends Broker
{
    /**
     * @return stdClass[]
     */
    public function findRecent(int $limit): array
    {
        return $this->select(
            "SELECT * FROM event.contract_event ORDER BY occurred_at DESC LIMIT ?",
            [$limit]
        );
    }

    public function countByName(string $eventName): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.contract_event WHERE event_name = ?",
            [$eventName]
        );
    }

    /**
     * Insert one row. Returns the new id, or null if the (tx_hash, log_index)
     * pair was already recorded (idempotent backfill).
     *
     * @param array<string, mixed> $payload arbitrary jsonb-able event fields
     */
    public function insert(
        string $eventName,
        array $payload,
        int $blockNumber,
        string $txHashBytes,
        int $logIndex,
        string $occurredAt
    ): ?int {
        $row = $this->selectOne(
            "INSERT INTO event.contract_event
                (event_name, payload, block_number, tx_hash, log_index, occurred_at)
             VALUES (?, ?::jsonb, ?, ?, ?, ?)
             ON CONFLICT (tx_hash, log_index) DO NOTHING
             RETURNING id",
            [
                $eventName,
                json_encode($payload, JSON_UNESCAPED_SLASHES),
                $blockNumber,
                $txHashBytes,
                $logIndex,
                $occurredAt,
            ]
        );
        return $row !== null ? (int) $row->id : null;
    }
}
