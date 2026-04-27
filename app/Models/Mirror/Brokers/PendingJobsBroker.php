<?php

declare(strict_types=1);

namespace App\Models\Mirror\Brokers;

use App\Models\Core\Broker;
use App\Models\Mirror\Entities\PendingJob;
use stdClass;

/**
 * Postgres queue for relayer jobs.
 *
 * The relayer claims one job per tick using a short transaction with
 * `FOR UPDATE SKIP LOCKED`, flips it to `in_flight`, then commits before
 * the slow Node-helper call so no row lock is held across the network tx.
 *
 * The reaper sweeps stale `in_flight` rows back to `pending` after a TTL,
 * so a relayer crash mid-job is recoverable.
 */
class PendingJobsBroker extends Broker
{
    /**
     * Enqueue a mirror job. Idempotent on (keccak_id, target_chain_id, job_type)
     * via the unique constraint - re-enqueues are silently ignored.
     */
    public function enqueueMirror(string $keccakIdHex, int $chainId, array $envelope): void
    {
        $this->insertJob($keccakIdHex, $chainId, PendingJob::TYPE_MIRROR, $envelope);
    }

    public function enqueueMirrorAddress(string $keccakIdHex, int $chainId, array $envelope, string $targetAddressHex): void
    {
        $envelope['target'] = self::lowerHex($targetAddressHex);
        $this->insertJob($keccakIdHex, $chainId, PendingJob::TYPE_MIRROR_ADDRESS, $envelope);
    }

    public function enqueueUnmirror(string $keccakIdHex, int $chainId): void
    {
        $this->insertJob($keccakIdHex, $chainId, PendingJob::TYPE_UNMIRROR, []);
    }

    /**
     * Atomically claim the next ready job: SELECT FOR UPDATE SKIP LOCKED, then
     * UPDATE to 'in_flight' with attempts incremented and claimed_at set.
     * Returns null if no job is ready.
     */
    public function claimNextReady(): ?PendingJob
    {
        $claimedId = $this->db->transaction(function () {
            $row = $this->selectOne(
                "SELECT id FROM mirror.pending_jobs
                  WHERE status = 'pending' AND next_attempt_at <= now()
                  ORDER BY enqueued_at ASC
                  LIMIT 1
                  FOR UPDATE SKIP LOCKED"
            );
            if ($row === null) {
                return null;
            }
            $this->db->query(
                "UPDATE mirror.pending_jobs
                    SET status     = 'in_flight',
                        attempts   = attempts + 1,
                        claimed_at = now()
                  WHERE id = ?",
                [(int) $row->id]
            );
            return (int) $row->id;
        });

        if ($claimedId === null) {
            return null;
        }
        // Re-read outside the claim tx to get the post-update view.
        $fresh = $this->selectOne(
            "SELECT * FROM mirror.pending_jobs WHERE id = ?",
            [$claimedId]
        );
        return $fresh === null ? null : PendingJob::build($fresh);
    }

    public function markSent(int $id, string $txHashHex): void
    {
        $this->db->query(
            "UPDATE mirror.pending_jobs
                SET status  = 'sent',
                    tx_hash = ?,
                    sent_at = now(),
                    last_error = NULL
              WHERE id = ?",
            ['\\x' . self::cleanHex($txHashHex), $id]
        );
    }

    public function markFailed(int $id, string $error): void
    {
        $this->db->query(
            "UPDATE mirror.pending_jobs
                SET status     = 'failed',
                    last_error = ?
              WHERE id = ?",
            [$error, $id]
        );
    }

    /**
     * Requeue a job after a transient failure. Resets status to 'pending' and
     * pushes next_attempt_at forward by `delaySeconds`.
     */
    public function requeueWithBackoff(int $id, string $error, int $delaySeconds): void
    {
        $this->db->query(
            "UPDATE mirror.pending_jobs
                SET status          = 'pending',
                    last_error      = ?,
                    next_attempt_at = now() + (? || ' seconds')::interval
              WHERE id = ?",
            [$error, $delaySeconds, $id]
        );
    }

    /**
     * Sweep stale in_flight rows back to pending. Protects against a relayer
     * crash between claim and outcome write.
     *
     * Returns the number of rows reaped.
     */
    public function reapStaleInFlight(int $olderThanSeconds = 300): int
    {
        $statement = $this->db->query(
            "UPDATE mirror.pending_jobs
                SET status     = 'pending',
                    last_error = COALESCE(last_error, '') || ' [reaped after ' || ? || 's stale]'
              WHERE status = 'in_flight'
                AND claimed_at < now() - (? || ' seconds')::interval",
            [$olderThanSeconds, $olderThanSeconds]
        );
        return $statement->rowCount();
    }

    public function countByStatus(string $status): int
    {
        return $this->selectInt(
            "SELECT count(*) FROM mirror.pending_jobs WHERE status = ?::mirror.job_status",
            [$status]
        );
    }

    public function findById(int $id): ?PendingJob
    {
        $row = $this->selectOne(
            "SELECT * FROM mirror.pending_jobs WHERE id = ?",
            [$id]
        );
        return $row === null ? null : PendingJob::build($row);
    }

    private function insertJob(string $keccakIdHex, int $chainId, string $jobType, array $payload): void
    {
        $this->db->query(
            "INSERT INTO mirror.pending_jobs
                (keccak_id, target_chain_id, job_type, payload)
             VALUES (?, ?, ?::mirror.job_type, ?::jsonb)
             ON CONFLICT (keccak_id, target_chain_id, job_type) DO NOTHING",
            [
                '\\x' . self::cleanHex($keccakIdHex),
                $chainId,
                $jobType,
                json_encode($payload, JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private static function cleanHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        return strtolower($hex);
    }

    private static function lowerHex(string $hex): string
    {
        return strtolower($hex);
    }
}
