<?php

declare(strict_types=1);

namespace App\Models\Indexer\Console;

/**
 * Tracks "is this named job due yet?" semantics for the Supervisor's tick
 * loop. The first call for each name returns true (so the job runs once at
 * startup); subsequent calls return true only after `intervalSeconds` have
 * elapsed since the last `due()` that returned true.
 */
class Cadence
{
    /** @var array<string, float> last-fired wall-clock seconds */
    private array $lastFired = [];

    public function __construct(private readonly ?\Closure $clock = null)
    {
    }

    public function due(string $name, int $intervalSeconds): bool
    {
        $now = $this->now();
        $last = $this->lastFired[$name] ?? null;
        if ($last === null || ($now - $last) >= $intervalSeconds) {
            $this->lastFired[$name] = $now;
            return true;
        }
        return false;
    }

    public function reset(string $name): void
    {
        unset($this->lastFired[$name]);
    }

    private function now(): float
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }
        return microtime(true);
    }
}
