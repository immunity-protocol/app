<?php

declare(strict_types=1);

namespace App\Models\Indexer\Brokers;

use App\Models\Core\Broker;
use App\Models\Indexer\Entities\HydrationJob;
use stdClass;

class HydrationQueueBroker extends Broker
{
    /**
     * Enqueue a hydration job. Idempotent on (antibody_keccak_id) for jobs
     * still pending; we do not re-enqueue if a pending row already exists.
     */
    public function enqueue(string $keccakIdBytes, string $evidenceCidBytes): void
    {
        $exists = $this->selectOne(
            "SELECT id FROM indexer.hydration_queue
              WHERE antibody_keccak_id = ? AND status = ?
              LIMIT 1",
            [$keccakIdBytes, HydrationJob::STATUS_PENDING]
        );
        if ($exists !== null) {
            return;
        }
        $this->db->query(
            "INSERT INTO indexer.hydration_queue
                (antibody_keccak_id, evidence_cid, status)
             VALUES (?, ?, ?)",
            [$keccakIdBytes, $evidenceCidBytes, HydrationJob::STATUS_PENDING]
        );
    }

    /**
     * @return stdClass[]
     */
    public function findPending(int $limit): array
    {
        return $this->select(
            "SELECT * FROM indexer.hydration_queue
              WHERE status = ?
              ORDER BY enqueued_at ASC
              LIMIT ?",
            [HydrationJob::STATUS_PENDING, $limit]
        );
    }

    public function markDone(int $id): void
    {
        $this->db->query(
            "UPDATE indexer.hydration_queue
                SET status = ?, last_error = NULL
              WHERE id = ?",
            [HydrationJob::STATUS_DONE, $id]
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->query(
            "UPDATE indexer.hydration_queue
                SET status = ?, last_error = ?
              WHERE id = ?",
            [HydrationJob::STATUS_FAILED, $error, $id]
        );
    }

    /**
     * Bump attempts and back off enqueued_at by 2^attempts * 30 seconds so
     * the partial pending index re-orders this row to the back of the queue.
     */
    public function backoff(int $id, int $attempts, string $error): void
    {
        $delaySeconds = (2 ** $attempts) * 30;
        $this->db->query(
            "UPDATE indexer.hydration_queue
                SET attempts    = ?,
                    last_error  = ?,
                    enqueued_at = now() + (? || ' seconds')::interval
              WHERE id = ?",
            [$attempts, $error, $delaySeconds, $id]
        );
    }

    public function countPending(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM indexer.hydration_queue WHERE status = ?",
            [HydrationJob::STATUS_PENDING]
        );
    }
}
