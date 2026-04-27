<?php

declare(strict_types=1);

namespace App\Models\Mirror\Workers;

use App\Models\Core\MirrorNetworkRegistry;
use App\Models\Mirror\Brokers\PendingJobsBroker;
use App\Models\Mirror\Entities\PendingJob;
use App\Models\Mirror\MirrorBridge;
use Throwable;

/**
 * One tick of the relayer: claim the next ready job, dispatch via MirrorBridge,
 * classify the outcome, persist the new state. Returns counts so the supervisor
 * can decide whether to keep draining or sleep.
 *
 * Each tick processes at most `batchSize` jobs (one per claim/send cycle).
 * Each job is wrapped in its own short DB transaction at claim time; the slow
 * Node helper call runs WITHOUT a row lock held.
 *
 * Outcome classification:
 *   ok=true                  -> markSent (indexer drives status -> confirmed
 *                               when it sees AntibodyMirrored on dest chain)
 *   ok=true,alreadyAbsent    -> markSent with synthetic tx hash (unmirror no-op)
 *   ok=false,permanent=true  -> markFailed
 *   ok=false,permanent=false -> requeueWithBackoff (exponential by attempts)
 */
class RelayerWorker
{
    public function __construct(
        private readonly PendingJobsBroker $jobs,
        private readonly MirrorNetworkRegistry $networks,
        private readonly MirrorBridge $bridge,
        private readonly int $maxRetries = 5,
        private readonly int $backoffBaseMs = 5000,
        private readonly int $batchSize = 10,
    ) {
    }

    /**
     * @return array{processed:int,sent:int,failed:int,backoff:int,absent:int,skipped:int}
     */
    public function tick(): array
    {
        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'backoff' => 0, 'absent' => 0, 'skipped' => 0];

        for ($i = 0; $i < $this->batchSize; $i++) {
            $job = $this->jobs->claimNextReady();
            if ($job === null) {
                break;
            }
            $stats['processed']++;

            $chain = $this->networks->get($job->target_chain_id);
            if ($chain === null) {
                $this->jobs->markFailed($job->id, "no MirrorNetworkRegistry entry for chain {$job->target_chain_id}");
                $stats['failed']++;
                continue;
            }

            $key = $chain->relayerPrivateKey();
            if ($key === null) {
                // Don't fail the job - other chains may have keys. Reverting to
                // pending with a long backoff lets the operator add the secret
                // and retry without losing state.
                $this->jobs->requeueWithBackoff(
                    $job->id,
                    "relayer key env {$chain->relayerPrivateKeyEnv} not set",
                    300
                );
                $stats['skipped']++;
                continue;
            }

            $envelope = $job->payloadArray();

            try {
                $result = $this->bridge->send($chain, $job, $key, $envelope);
            } catch (Throwable $e) {
                $this->onTransient($job, "bridge exception: " . $e->getMessage());
                $stats['backoff']++;
                continue;
            }

            $ok = (bool) ($result['ok'] ?? false);
            $absent = (bool) ($result['alreadyAbsent'] ?? false);

            if ($ok && $absent) {
                $this->jobs->markSent($job->id, str_repeat('0', 64));
                $stats['absent']++;
                continue;
            }
            if ($ok) {
                $txHash = (string) ($result['txHash'] ?? '');
                if ($txHash === '') {
                    $this->onTransient($job, 'helper returned ok=true with no txHash');
                    $stats['backoff']++;
                    continue;
                }
                $this->jobs->markSent($job->id, $txHash);
                $stats['sent']++;
                continue;
            }

            $error = (string) ($result['error'] ?? 'unknown error');
            $code = (string) ($result['code'] ?? 'UNKNOWN');
            $permanent = (bool) ($result['permanent'] ?? false);
            $msg = "[$code] $error";

            if ($permanent) {
                $this->jobs->markFailed($job->id, $msg);
                $stats['failed']++;
                continue;
            }

            // Transient: backoff or escalate after maxRetries.
            if ($job->attempts >= $this->maxRetries) {
                $this->jobs->markFailed($job->id, "max retries ($this->maxRetries) exceeded: $msg");
                $stats['failed']++;
                continue;
            }
            $this->onTransient($job, $msg);
            $stats['backoff']++;
        }

        return $stats;
    }

    private function onTransient(PendingJob $job, string $msg): void
    {
        // Exponential backoff: base * 2^(attempts-1). attempts is already
        // incremented at claim time, so the first failure waits base * 1.
        $exponent = max(0, $job->attempts - 1);
        $delayMs = $this->backoffBaseMs * (2 ** $exponent);
        $delaySec = max(1, (int) ceil($delayMs / 1000));
        $this->jobs->requeueWithBackoff($job->id, $msg, $delaySec);
    }
}
