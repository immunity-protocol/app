<?php

declare(strict_types=1);

namespace App\Models\Indexer\Brokers;

use App\Models\Core\Broker;
use App\Models\Indexer\Entities\State;
use stdClass;

class StateBroker extends Broker
{
    public function find(): ?stdClass
    {
        return $this->selectOne("SELECT * FROM indexer.state WHERE id = 1");
    }

    public function ensure(int $defaultLastProcessedBlock, string $defaultMode = State::MODE_BACKFILLING): stdClass
    {
        $row = $this->find();
        if ($row !== null) {
            return $row;
        }
        $this->db->query(
            "INSERT INTO indexer.state (id, last_processed_block, mode, last_run_at)
             VALUES (1, ?, ?, now())
             ON CONFLICT (id) DO NOTHING",
            [$defaultLastProcessedBlock, $defaultMode]
        );
        return $this->find();
    }

    public function advance(int $lastProcessedBlock, string $mode): void
    {
        $this->db->query(
            "UPDATE indexer.state
                SET last_processed_block = ?,
                    mode = ?,
                    last_run_at = now()
              WHERE id = 1",
            [$lastProcessedBlock, $mode]
        );
    }

    public function touch(): void
    {
        $this->db->query("UPDATE indexer.state SET last_run_at = now() WHERE id = 1");
    }
}
