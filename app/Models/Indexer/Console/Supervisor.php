<?php

declare(strict_types=1);

namespace App\Models\Indexer\Console;

use App\Models\Indexer\Workers\BackfillBootstrap;
use App\Models\Indexer\Workers\EnsResolutionWorker;
use App\Models\Indexer\Workers\EventPoller;
use App\Models\Indexer\Workers\ExpirySweep;
use App\Models\Indexer\Workers\HydrationWorker;
use App\Models\Indexer\Workers\PricingRetryWorker;
use App\Models\Indexer\Workers\StatRefresher;
use Throwable;

/**
 * Single-process indexer supervisor.
 *
 * Runs sequential cadence-aware loops in one PHP process:
 *   - EventPoller       : every poll-interval (default 2s)
 *   - HydrationWorker   : every poll-interval, capped at maxHydrationJobs
 *   - ExpirySweep       : every 60s
 *   - EnsResolutionWorker: every 30s
 *   - StatRefresher     : every 60s
 *   - SummaryLogger     : every 60s
 *
 * Handles SIGTERM / SIGINT to stop cleanly between iterations. Memory ceiling
 * triggers a non-zero exit so the orchestrator (Docker / Fly) restarts.
 */
class Supervisor
{
    private bool $shouldExit = false;
    private int $iterations = 0;
    private int $totalEvents = 0;
    private int $totalHydrated = 0;
    private int $totalExpired = 0;

    public function __construct(
        private readonly BackfillBootstrap $bootstrap,
        private readonly EventPoller $poller,
        private readonly HydrationWorker $hydration,
        private readonly ExpirySweep $expiry,
        private readonly ?EnsResolutionWorker $ens,
        private readonly StatRefresher $statRefresher,
        private readonly Cadence $cadence,
        private readonly ?PricingRetryWorker $pricingRetry = null,
        private readonly int $pollIntervalMs = 2000,
        private readonly int $maxHydrationJobs = 5,
        private readonly int $memoryCeilingMb = 256,
    ) {
    }

    public function run(): int
    {
        $this->installSignalHandlers();
        $boot = $this->bootstrap->bootstrap();
        $this->log(sprintf(
            "boot: last_processed_block=%d mode=%s seeded=%s",
            $boot['last_processed_block'],
            $boot['mode'],
            $boot['seeded'] ? 'yes' : 'no'
        ));

        $intervalSec = max(0.05, $this->pollIntervalMs / 1000.0);

        while (!$this->shouldExit) {
            $start = microtime(true);
            $this->iterations++;

            try {
                $this->totalEvents += $this->poller->tick();
            } catch (Throwable $e) {
                $this->log("event-poller error: " . $e->getMessage());
            }

            try {
                $hydStats = $this->hydration->tick($this->maxHydrationJobs);
                $this->totalHydrated += (int) $hydStats['succeeded'];
            } catch (Throwable $e) {
                $this->log("hydration error: " . $e->getMessage());
            }

            if ($this->cadence->due('expiry_sweep', 60)) {
                try {
                    $this->totalExpired += $this->expiry->run();
                } catch (Throwable $e) {
                    $this->log("expiry error: " . $e->getMessage());
                }
            }

            if ($this->ens !== null && $this->cadence->due('ens_resolution', 30)) {
                try {
                    $this->ens->tick();
                } catch (Throwable $e) {
                    $this->log("ens error: " . $e->getMessage());
                }
            }

            if ($this->cadence->due('stat_refresh', 60)) {
                try {
                    $this->statRefresher->run();
                } catch (Throwable $e) {
                    $this->log("stat-refresh error: " . $e->getMessage());
                }
            }

            if ($this->pricingRetry !== null && $this->cadence->due('pricing_retry', 60)) {
                try {
                    $this->pricingRetry->tick();
                } catch (Throwable $e) {
                    $this->log("pricing-retry error: " . $e->getMessage());
                }
            }

            if ($this->cadence->due('summary_log', 60)) {
                $this->log(sprintf(
                    "summary: iter=%d events=%d hydrated=%d expired=%d mem=%dMB",
                    $this->iterations,
                    $this->totalEvents,
                    $this->totalHydrated,
                    $this->totalExpired,
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
}
