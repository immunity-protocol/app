<?php

declare(strict_types=1);

namespace App\Models\Indexer\Workers;

use App\Models\Indexer\Brokers\HydrationQueueBroker;
use App\Models\Indexer\Storage\NodeBridge;
use Throwable;
use Zephyrus\Data\Database;

/**
 * Drains pending hydration jobs from indexer.hydration_queue. For each job we
 * shell out to scripts/og-download.mjs to fetch the antibody envelope from
 * 0G Storage at the evidence_cid root hash, then hydrate the antibody row's
 * primary_matcher / redacted_reasoning columns from the envelope payload.
 *
 * Failures back off (2^attempts * 30s) up to 3 attempts; after that the job
 * is marked failed and skipped permanently.
 */
class HydrationWorker
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Database $db,
        private readonly HydrationQueueBroker $queue,
        private readonly NodeBridge $bridge,
    ) {
    }

    /**
     * Process up to $maxJobs pending jobs in this tick.
     *
     * @return array{processed:int,succeeded:int,failed:int,backed_off:int}
     */
    public function tick(int $maxJobs = 5): array
    {
        $jobs = $this->queue->findPending($maxJobs);
        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'backed_off' => 0];

        foreach ($jobs as $job) {
            $stats['processed']++;
            $jobId = (int) $job->id;
            $attempts = (int) $job->attempts + 1;
            $rootHashHex = '0x' . bin2hex((string) $job->evidence_cid);
            $keccakHex = bin2hex((string) $job->antibody_keccak_id);

            try {
                $envelope = $this->bridge->downloadEnvelope($rootHashHex);
            } catch (Throwable $e) {
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->queue->markFailed($jobId, $e->getMessage());
                    $stats['failed']++;
                } else {
                    $this->queue->backoff($jobId, $attempts, $e->getMessage());
                    $stats['backed_off']++;
                }
                continue;
            }

            // Empty CID is success, no envelope to apply.
            if ($envelope !== null) {
                $primaryMatcher = $envelope['matcher'] ?? null;
                $reasonSummary = $envelope['reasonSummary'] ?? null;

                $this->db->query(
                    "UPDATE antibody.entry SET
                        primary_matcher    = COALESCE(?::jsonb, primary_matcher),
                        redacted_reasoning = COALESCE(?, redacted_reasoning),
                        updated_at         = now()
                      WHERE keccak_id = ?",
                    [
                        $primaryMatcher !== null ? json_encode($primaryMatcher, JSON_UNESCAPED_SLASHES) : null,
                        $reasonSummary,
                        '\\x' . $keccakHex,
                    ]
                );
            }

            $this->queue->markDone($jobId);
            $stats['succeeded']++;
        }

        return $stats;
    }
}
