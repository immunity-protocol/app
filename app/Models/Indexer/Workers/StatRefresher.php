<?php

declare(strict_types=1);

namespace App\Models\Indexer\Workers;

use App\Models\Network\Brokers\StatBroker;
use Zephyrus\Data\Database;

/**
 * Computes network-wide dashboard metrics from real database state and inserts a
 * fresh row into network.stat. The dashboard's "alive" indicator considers
 * data stale at >120s, so the supervisor schedules this every 60s.
 *
 * Demo-bounded metrics (e.g. `agents_online`) are NOT in this set — they are
 * read live from the `demo.*` schema by the API endpoint that serves them.
 * Network.stat is reserved for cumulative metrics that are expensive to
 * compute on every poll.
 *
 * - antibodies_active   : count of antibody.entry where status = active
 * - cache_hits_per_hour : count of event.check_event with cache_hit in last hour
 * - llm_calls_saved     : ALL-TIME count of cache hits — each one avoided a TEE
 *                         inference round-trip, so the lifetime total is the
 *                         "compute saved" number we surface on the landing page.
 * - value_protected_usd : SUM(value_protected_usd) on event.block_event since
 *                         the indexer started; v1 has no SDK telemetry channel
 *                         so this stays at 0 until the relayer piles it in.
 */
class StatRefresher
{
    private const METRICS = [
        'antibodies_active',
        'cache_hits_per_hour',
        'llm_calls_saved',
        'value_protected_usd',
        'publishers_total',
        'publisher_earnings_total_usdc',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly StatBroker $stats,
    ) {
    }

    public function run(): void
    {
        $values = [
            'antibodies_active'   => $this->scalar(
                "SELECT count(*) FROM antibody.entry WHERE status = 'active'::antibody.entry_status"
            ),
            'cache_hits_per_hour' => $this->scalar(
                "SELECT count(*) FROM event.check_event
                   WHERE cache_hit = true AND occurred_at >= now() - interval '1 hour'"
            ),
            'llm_calls_saved'     => $this->scalar(
                "SELECT count(*) FROM event.check_event WHERE cache_hit = true"
            ),
            'value_protected_usd' => $this->scalar(
                "SELECT COALESCE(SUM(value_protected_usd), 0) FROM event.block_event"
            ),
            // Network-contributors leaderboard headline (also on /publishers).
            // Sourced from antibody.publisher, which the AntibodyMatched
            // handler upserts on every match — so the snapshot stays in
            // sync with the underlying per-publisher counters.
            'publishers_total' => $this->scalar(
                "SELECT count(*) FROM antibody.publisher"
            ),
            'publisher_earnings_total_usdc' => $this->scalar(
                "SELECT COALESCE(SUM(total_earned_usdc), 0) FROM antibody.publisher"
            ),
        ];

        $rows = [];
        $nowIso = gmdate('Y-m-d H:i:sP');
        foreach (self::METRICS as $metric) {
            $rows[] = [
                'metric'      => $metric,
                'value'       => sprintf('%.6f', (float) $values[$metric]),
                'captured_at' => $nowIso,
            ];
        }
        $this->stats->insertBulk($rows);
    }

    private function scalar(string $sql): string
    {
        $stmt = $this->db->query($sql);
        $val = $stmt->fetchColumn();
        return (string) ($val === false ? 0 : $val);
    }
}
