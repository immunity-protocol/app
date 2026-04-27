<?php

declare(strict_types=1);

namespace App\Models\Indexer\Brokers;

use App\Models\Core\Broker;
use App\Models\Indexer\Entities\State;
use stdClass;

class StateBroker extends Broker
{
    public function find(int $chainId): ?stdClass
    {
        return $this->selectOne(
            "SELECT * FROM indexer.state WHERE chain_id = ?",
            [$chainId]
        );
    }

    public function ensure(int $chainId, int $defaultLastProcessedBlock, string $defaultMode = State::MODE_BACKFILLING): stdClass
    {
        $row = $this->find($chainId);
        if ($row !== null) {
            return $row;
        }
        $this->db->query(
            "INSERT INTO indexer.state (chain_id, last_processed_block, mode, last_run_at)
             VALUES (?, ?, ?, now())
             ON CONFLICT (chain_id) DO NOTHING",
            [$chainId, $defaultLastProcessedBlock, $defaultMode]
        );
        return $this->find($chainId);
    }

    public function advance(int $chainId, int $lastProcessedBlock, string $mode): void
    {
        $this->db->query(
            "UPDATE indexer.state
                SET last_processed_block = ?,
                    mode = ?,
                    last_run_at = now()
              WHERE chain_id = ?",
            [$lastProcessedBlock, $mode, $chainId]
        );
    }

    public function touch(int $chainId): void
    {
        $this->db->query(
            "UPDATE indexer.state SET last_run_at = now() WHERE chain_id = ?",
            [$chainId]
        );
    }
}
