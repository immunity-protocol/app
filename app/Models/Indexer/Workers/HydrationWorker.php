<?php

declare(strict_types=1);

namespace App\Models\Indexer\Workers;

use App\Models\Indexer\Brokers\HydrationQueueBroker;
use App\Models\Indexer\Storage\LighthouseFetcher;
use Throwable;
use Zephyrus\Data\Database;

/**
 * Drains pending hydration jobs from indexer.hydration_queue. For each job we
 * reconstruct the Lighthouse CID from the on-chain evidence_cid digest, fetch
 * the antibody public envelope from the keyless IPFS gateway, then hydrate the
 * antibody row's primary_matcher / redacted_reasoning columns from the payload.
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
        private readonly LighthouseFetcher $fetcher,
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
            $keccakHex = bin2hex((string) $job->antibody_keccak_id);

            try {
                $envelope = $this->fetcher->downloadEnvelope((string) $job->evidence_cid);
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

                // The Published event carries no prominenceTier, so derive it here
                // once the matcher's target is known: a flag on a protected address
                // is prominence tier 1 (the protected-set / autoimmune-attack signal).
                // Use the worker's own DB handle — instantiating a Broker here calls
                // Db::current(), which is not bootstrapped in the indexer context.
                $target = is_array($primaryMatcher) ? ($primaryMatcher['target'] ?? null) : null;
                $prominence = null;
                if (is_string($target) && str_starts_with($target, '0x')) {
                    $hit = $this->db->query(
                        "SELECT 1 AS ok FROM antibody.protected_target
                          WHERE address = decode(?, 'hex') AND protected = true",
                        [strtolower(substr($target, 2))]
                    )->fetch();
                    if ($hit !== false && $hit !== null) {
                        $prominence = 1;
                    }
                }

                $this->db->query(
                    "UPDATE antibody.entry SET
                        primary_matcher    = COALESCE(?::jsonb, primary_matcher),
                        redacted_reasoning = COALESCE(?, redacted_reasoning),
                        prominence_tier    = COALESCE(?, prominence_tier),
                        updated_at         = now()
                      WHERE keccak_id = ?",
                    [
                        $primaryMatcher !== null ? json_encode($primaryMatcher, JSON_UNESCAPED_SLASHES) : null,
                        $reasonSummary,
                        $prominence,
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
