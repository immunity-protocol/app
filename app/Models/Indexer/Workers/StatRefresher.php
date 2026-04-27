<?php

declare(strict_types=1);

namespace App\Models\Indexer\Workers;

use App\Models\Network\Brokers\StatBroker;
use Zephyrus\Data\Database;

/**
 * Computes the 5 dashboard metrics from real database state and inserts a
 * fresh row into network.stat. The dashboard's "alive" indicator considers
 * data stale at >120s, so the supervisor schedules this every 60s.
 *
 * - antibodies_active   : count of antibody.entry where status = active
 * - agents_online       : count of agent.heartbeat with last_seen in last 60s
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
        'agents_online',
        'cache_hits_per_hour',
        'llm_calls_saved',
        'value_protected_usd',
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
            'agents_online'       => $this->scalar(
                "SELECT count(*) FROM agent.heartbeat WHERE last_seen >= now() - interval '60 seconds'"
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
