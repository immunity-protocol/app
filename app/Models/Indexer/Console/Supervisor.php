<?php

declare(strict_types=1);

namespace App\Models\Indexer\Console;

use App\Models\Indexer\Workers\BackfillBootstrap;
use App\Models\Indexer\Workers\EnsResolutionWorker;
use App\Models\Indexer\Workers\EventPoller;
use App\Models\Indexer\Workers\HydrationWorker;
use App\Models\Indexer\Workers\PricingRetryWorker;
use App\Models\Indexer\Workers\StatRefresher;
use Throwable;

/**
 * Single-process indexer supervisor.
 *
 * Runs sequential cadence-aware loops in one PHP process:
 *   - EventPoller       : every poll-interval (default 15s)
 *   - HydrationWorker   : every poll-interval, capped at maxHydrationJobs
 *   - EnsResolutionWorker: once per day
 *   - StatRefresher     : every 60s
 *   - SummaryLogger     : every 60s
 *
 * Handles SIGTERM / SIGINT to stop cleanly between iterations. Memory ceiling
 * triggers a non-zero exit so the orchestrator (Docker / Fly) restarts.
 *
 * Connection-lost detection: PDO does not auto-reconnect when its TCP
 * socket dies (Fly Postgres maintenance, idle timeout, network blip).
 * Once broken, every subsequent query fails with the same "no connection
 * to the server" error forever — exactly the failure that left dashboards
 * showing stale stats for two hours today. Rather than rebuild the entire
 * dependency graph mid-loop, we count consecutive connection-lost errors
 * across worker calls and exit non-zero on the threshold so Fly restarts
 * the process with a fresh PDO. This mirrors the memory-ceiling exit path.
 *
 * v1 antibodies are permanent (`expires_at` is reserved for v2), so the
 * ExpirySweep worker is no longer registered here. Re-add the constructor
 * argument and the cadence block when v2 honors expiries.
 */
class Supervisor
{
    private bool $shouldExit = false;
    private int $iterations = 0;
    private int $totalEvents = 0;
    private int $totalHydrated = 0;
    private int $consecutiveConnLostErrors = 0;
    private const ENS_RESOLUTION_INTERVAL_SEC = 86_400;
    private const CONN_LOST_EXIT_THRESHOLD = 3;

    /**
     * @param BackfillBootstrap[] $bootstraps  one per chain (0G + each Mirror chain)
     * @param array<int, array{poller: EventPoller, intervalSec: int}> $pollers
     *        one entry per chain. The intervalSec is the per-chain cadence
     *        (overrides the supervisor's global tick when larger). Tuples
     *        keep the configuration flexible: Galileo can poll every 2s
     *        while Sepolia polls every hour, all from the same loop.
     */
    public function __construct(
        private readonly array $bootstraps,
        private readonly array $pollers,
        private readonly HydrationWorker $hydration,
        private readonly ?EnsResolutionWorker $ens,
        private readonly StatRefresher $statRefresher,
        private readonly Cadence $cadence,
        private readonly ?PricingRetryWorker $pricingRetry = null,
        private readonly int $pollIntervalMs = 15_000,
        private readonly int $maxHydrationJobs = 5,
        private readonly int $memoryCeilingMb = 256,
    ) {
    }

    public function run(): int
    {
        $this->installSignalHandlers();
        foreach ($this->bootstraps as $bootstrap) {
            $boot = $bootstrap->bootstrap();
            $this->log(sprintf(
                "boot chain=%d last_processed_block=%d mode=%s seeded=%s",
                (int) ($boot['chain_id'] ?? 0),
                $boot['last_processed_block'],
                $boot['mode'],
                $boot['seeded'] ? 'yes' : 'no'
            ));
        }

        $intervalSec = max(0.05, $this->pollIntervalMs / 1000.0);

        while (!$this->shouldExit) {
            $start = microtime(true);
            $this->iterations++;

            foreach ($this->pollers as $entry) {
                $poller = $entry['poller'];
                // Locally scoped so we don't shadow the outer loop's
                // $intervalSec (which controls the supervisor's own sleep).
                $pollerIntervalSec = max(1, (int) $entry['intervalSec']);
                $cadenceKey = "event_poller_chain_" . $poller->chainId();
                if (!$this->cadence->due($cadenceKey, $pollerIntervalSec)) {
                    continue;
                }
                try {
                    $this->totalEvents += $poller->tick();
                    $this->onWorkerSuccess();
                } catch (Throwable $e) {
                    $this->log("event-poller chain=" . $poller->chainId() . " error: " . $e->getMessage());
                    if ($this->isConnectionLost($e)) {
                        $code = $this->bailOnLostConnection();
                        if ($code !== null) {
                            return $code;
                        }
                    }
                }
            }

            try {
                $hydStats = $this->hydration->tick($this->maxHydrationJobs);
                $this->totalHydrated += (int) $hydStats['succeeded'];
                $this->onWorkerSuccess();
            } catch (Throwable $e) {
                $this->log("hydration error: " . $e->getMessage());
                if ($this->isConnectionLost($e)) {
                    return $this->bailOnLostConnection();
                }
            }

            if ($this->ens !== null && $this->cadence->due('ens_resolution', self::ENS_RESOLUTION_INTERVAL_SEC)) {
                try {
                    $this->ens->tick();
                    $this->onWorkerSuccess();
                } catch (Throwable $e) {
                    $this->log("ens error: " . $e->getMessage());
                    if ($this->isConnectionLost($e)) {
                        $code = $this->bailOnLostConnection();
                        if ($code !== null) {
                            return $code;
                        }
                    }
                }
            }

            if ($this->cadence->due('stat_refresh', 60)) {
                try {
                    $this->statRefresher->run();
                    $this->onWorkerSuccess();
                } catch (Throwable $e) {
                    $this->log("stat-refresh error: " . $e->getMessage());
                    if ($this->isConnectionLost($e)) {
                        $code = $this->bailOnLostConnection();
                        if ($code !== null) {
                            return $code;
                        }
                    }
                }
            }

            if ($this->pricingRetry !== null && $this->cadence->due('pricing_retry', 60)) {
                try {
                    $this->pricingRetry->tick();
                    $this->onWorkerSuccess();
                } catch (Throwable $e) {
                    $this->log("pricing-retry error: " . $e->getMessage());
                    if ($this->isConnectionLost($e)) {
                        $code = $this->bailOnLostConnection();
                        if ($code !== null) {
                            return $code;
                        }
                    }
                }
            }

            if ($this->cadence->due('summary_log', 60)) {
                $this->log(sprintf(
                    "summary: iter=%d events=%d hydrated=%d mem=%dMB",
                    $this->iterations,
                    $this->totalEvents,
                    $this->totalHydrated,
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

        $this->log("graceful shutdown after $this->iterations iterations");
        return 0;
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
        $line = sprintf('[%s] indexer: %s', gmdate('Y-m-d\TH:i:s\Z'), $message);
        fwrite(STDOUT, $line . PHP_EOL);
    }

    /**
     * "no connection to the server" / "server has gone away" / "Connection
     * refused" all indicate that PDO's TCP socket is dead and PDO will not
     * recover on its own. We match on the substrings rather than SQLSTATE
     * codes because SQLSTATE for these typically reads HY000 (generic) and
     * the only reliable signal is the driver-level message.
     */
    private function isConnectionLost(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'no connection to the server')
            || str_contains($msg, 'server has gone away')
            || str_contains($msg, 'connection refused')
            || str_contains($msg, 'broken pipe')
            || str_contains($msg, 'lost connection');
    }

    private function onWorkerSuccess(): void
    {
        $this->consecutiveConnLostErrors = 0;
    }

    /**
     * Increment the connection-lost counter and request a non-zero exit
     * once we cross the threshold. Returns the exit code (3) when the
     * threshold is reached, otherwise null — callers should `return` the
     * non-null value from `run()` so the orchestrator restarts the process
     * with a fresh PDO.
     */
    private function bailOnLostConnection(): ?int
    {
        $this->consecutiveConnLostErrors++;
        if ($this->consecutiveConnLostErrors < self::CONN_LOST_EXIT_THRESHOLD) {
            return null;
        }
        $this->log(sprintf(
            "connection lost %d times in a row, exiting for restart",
            $this->consecutiveConnLostErrors
        ));
        return 3;
    }
}
