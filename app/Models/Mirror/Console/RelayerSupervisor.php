<?php

declare(strict_types=1);

namespace App\Models\Mirror\Console;

use App\Models\Indexer\Console\Cadence;
use App\Models\Mirror\Brokers\PendingJobsBroker;
use App\Models\Mirror\Workers\RelayerWorker;
use Throwable;
use Zephyrus\Data\Database;

/**
 * Single-process relayer supervisor. Wraps the RelayerWorker tick loop with:
 *   - Postgres advisory lock (refuse to start if another relayer is running)
 *   - SIGTERM / SIGINT graceful shutdown
 *   - Memory ceiling -> exit code 2 so the orchestrator restarts a clean process
 *   - Cadence-driven stale-claim reaper (every 60s)
 *   - Summary log every 60s
 *
 * The advisory lock matters because nonces are per-wallet-per-chain; concurrent
 * relayers would collide on tx submission.
 */
class RelayerSupervisor
{
    public const ADVISORY_LOCK_KEY = 0x1A1B0CC0;

    private bool $shouldExit = false;
    private int $iterations = 0;
    private int $totalSent = 0;
    private int $totalFailed = 0;
    private int $totalBackoff = 0;
    private int $totalAbsent = 0;
    private int $totalSkipped = 0;
    private int $totalReaped = 0;

    public function __construct(
        private readonly Database $db,
        private readonly RelayerWorker $worker,
        private readonly PendingJobsBroker $jobs,
        private readonly Cadence $cadence,
        private readonly int $pollIntervalMs = 2000,
        private readonly int $reapIntervalSec = 60,
        private readonly int $reapStaleSec = 300,
        private readonly int $memoryCeilingMb = 256,
    ) {
    }

    public function run(): int
    {
        $this->installSignalHandlers();

        if (!$this->acquireAdvisoryLock()) {
            $this->log('another relayer holds the advisory lock; exiting');
            return 1;
        }

        $this->log(sprintf('boot: poll=%dms reap=%ds stale=%ds memCeil=%dMB',
            $this->pollIntervalMs, $this->reapIntervalSec, $this->reapStaleSec, $this->memoryCeilingMb));

        $intervalSec = max(0.05, $this->pollIntervalMs / 1000.0);

        try {
            while (!$this->shouldExit) {
                $start = microtime(true);
                $this->iterations++;

                try {
                    $stats = $this->worker->tick();
                    $this->totalSent    += (int) ($stats['sent']    ?? 0);
                    $this->totalFailed  += (int) ($stats['failed']  ?? 0);
                    $this->totalBackoff += (int) ($stats['backoff'] ?? 0);
                    $this->totalAbsent  += (int) ($stats['absent']  ?? 0);
                    $this->totalSkipped += (int) ($stats['skipped'] ?? 0);
                } catch (Throwable $e) {
                    $this->log('worker error: ' . $e->getMessage());
                }

                if ($this->cadence->due('reap_stale', $this->reapIntervalSec)) {
                    try {
                        $reaped = $this->jobs->reapStaleInFlight($this->reapStaleSec);
                        if ($reaped > 0) {
                            $this->totalReaped += $reaped;
                            $this->log("reaped $reaped stale in_flight job(s)");
                        }
                    } catch (Throwable $e) {
                        $this->log('reaper error: ' . $e->getMessage());
                    }
                }

                if ($this->cadence->due('summary_log', 60)) {
                    $this->log(sprintf(
                        'summary: iter=%d sent=%d failed=%d backoff=%d absent=%d skipped=%d reaped=%d mem=%dMB',
                        $this->iterations,
                        $this->totalSent,
                        $this->totalFailed,
                        $this->totalBackoff,
                        $this->totalAbsent,
                        $this->totalSkipped,
                        $this->totalReaped,
                        (int) (memory_get_usage(true) / 1048576)
                    ));
                }

                $this->dispatchSignals();
                if ($this->shouldExit) {
                    break;
                }

                $memMb = (int) (memory_get_usage(true) / 1048576);
                if ($memMb > $this->memoryCeilingMb) {
                    $this->log("memory ceiling hit ({$memMb}MB > {$this->memoryCeilingMb}MB), exiting for restart");
                    return 2;
                }

                $elapsed = microtime(true) - $start;
                $sleep = max(0.0, $intervalSec - $elapsed);
                if ($sleep > 0) {
                    usleep((int) ($sleep * 1_000_000));
                }
            }
        } finally {
            $this->releaseAdvisoryLock();
        }

        $this->log("graceful shutdown after $this->iterations iterations");
        return 0;
    }

    private function acquireAdvisoryLock(): bool
    {
        $row = $this->db->selectOne(
            "SELECT pg_try_advisory_lock(?) AS got",
            [self::ADVISORY_LOCK_KEY]
        );
        return $row !== null && $row->got === true;
    }

    private function releaseAdvisoryLock(): void
    {
        try {
            $this->db->query("SELECT pg_advisory_unlock(?)", [self::ADVISORY_LOCK_KEY]);
        } catch (Throwable $e) {
            // Lock auto-releases on connection close anyway.
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void { $this->shouldExit = true; });
        pcntl_signal(SIGINT, function (): void { $this->shouldExit = true; });
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    private function log(string $message): void
    {
        $line = sprintf('[%s] relayer: %s', gmdate('Y-m-d\TH:i:s\Z'), $message);
        fwrite(STDOUT, $line . PHP_EOL);
    }
}
