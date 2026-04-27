<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mock;

use App\Models\Agent\Brokers\HeartbeatBroker;
use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Antibody\Brokers\MirrorBroker;
use App\Models\Antibody\Brokers\PublisherBroker;
use App\Models\Event\Brokers\ActivityBroker;
use App\Models\Event\Brokers\BlockEventBroker;
use App\Models\Event\Brokers\CheckEventBroker;
use App\Models\Network\Brokers\StatBroker;
use Throwable;
use Zephyrus\Data\Database;

/**
 * One iteration of mock network activity. Called every 60s by the CRON tier.
 *
 * Per tick:
 *   - 60% insert one new check_event
 *   - 15% insert one new block_event (with its own check_event)
 *   -  8% publish one new antibody (with derived activity row)
 *   -  5% mirror an existing ADDRESS antibody to a new chain
 *   - always: insert one new network.stat row per metric
 *   - always: refresh ~95% of heartbeats; ~5% become stale (skipped)
 *
 * Concurrent runs are guarded by a Postgres advisory lock; if the lock is
 * already held the ticker returns an empty tally instead of double-writing.
 */
final class Ticker
{
    public const ADVISORY_LOCK_KEY = 0x1AA2026;

    public function __construct(
        private readonly Database $db,
    ) {
    }

    /**
     * @return array<string, int> tally of writes (or `['skipped' => 1]` when locked)
     */
    public function run(): array
    {
        $row = $this->db->selectOne(
            "SELECT pg_try_advisory_lock(?) AS got",
            [self::ADVISORY_LOCK_KEY]
        );
        if ($row === null || $row->got !== true) {
            return ['skipped' => 1];
        }

        try {
            return $this->doRun();
        } finally {
            $this->db->query("SELECT pg_advisory_unlock(?)", [self::ADVISORY_LOCK_KEY]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function doRun(): array
    {
        $tally = [
            'check_events'   => 0,
            'block_events'   => 0,
            'entries'        => 0,
            'mirrors'        => 0,
            'heartbeats'     => 0,
            'network_stats'  => 0,
        ];

        $entryBroker = new EntryBroker($this->db);
        $mirrorBroker = new MirrorBroker($this->db);
        $checkBroker = new CheckEventBroker($this->db);
        $blockBroker = new BlockEventBroker($this->db);
        $heartbeatBroker = new HeartbeatBroker($this->db);
        $statBroker = new StatBroker($this->db);
        $activityBroker = new ActivityBroker($this->db);
        $publisherBroker = new PublisherBroker($this->db);

        // Use a per-tick seed derived from the wall clock so each run differs.
        Seeds::reset((int) (microtime(true) * 1000) & 0xFFFFFFFF);

        $existingEntries = $entryBroker->findRecent(200);
        $entryRefs = array_map(
            static fn ($row) => ['id' => (int) $row->id, 'type' => $row->type],
            $existingEntries
        );

        // 1. New check event (60%).
        if (Seeds::chance(0.60) && $entryRefs !== []) {
            $factory = new CheckEventFactory(total: 1);
            $generated = $factory->generate($entryRefs);
            foreach ($generated['checks'] as $row) {
                $checkBroker->insert($row);
                $tally['check_events']++;
            }
        }

        // 2. New block event (15%) - requires a check + block in tandem.
        if (Seeds::chance(0.15) && $entryRefs !== []) {
            $factory = new CheckEventFactory(total: 1);
            // Force a block by setting decision when generating.
            $checks = [];
            foreach ($factory->generate($entryRefs)['checks'] as $row) {
                $row['decision'] = 'block';
                $row['matched_entry_id'] = Seeds::pick($entryRefs)['id'];
                $row['confidence'] = Seeds::int(80, 99);
                $row['cache_hit'] = Seeds::chance(0.65) ? 'true' : 'false';
                $row['tee_used'] = $row['cache_hit'] === 'false' ? 'true' : 'false';
                $checks[] = $row;
            }
            foreach ($checks as $checkRow) {
                $checkId = $checkBroker->insert($checkRow);
                $tally['check_events']++;
                $blockBroker->insert([
                    'check_event_id'      => $checkId,
                    'entry_id'            => $checkRow['matched_entry_id'],
                    'agent_id'            => $checkRow['agent_id'],
                    'value_protected_usd' => sprintf('%.6f', max(50.0, Seeds::logNormal(300.0, 1.4))),
                    'tx_hash_attempt'     => '\\x' . bin2hex(random_bytes(32)),
                    'chain_id'            => $checkRow['chain_id'],
                    'occurred_at'         => $checkRow['occurred_at'],
                ]);
                $tally['block_events']++;
                $activityBroker->insert([
                    'event_type' => 'protected',
                    'entry_id'   => $checkRow['matched_entry_id'],
                    'payload'    => json_encode(['agent_id' => $checkRow['agent_id']]),
                    'actor'      => $checkRow['agent_id'],
                ]);
            }
        }

        // 3. New antibody (8%).
        if (Seeds::chance(0.08)) {
            $publishers = new PublisherFactory(20);
            $entryFactory = new EntryFactory($publishers, total: 1, recentBurst: 1);
            foreach ($entryFactory->generate() as $row) {
                $row['imm_id'] = $this->nextImmId($entryBroker);
                $row['created_at'] = gmdate('Y-m-d H:i:sP');
                $row['updated_at'] = $row['created_at'];
                $newId = $entryBroker->insert($row);
                $tally['entries']++;
                $activityBroker->insert([
                    'event_type' => 'published',
                    'entry_id'   => $newId,
                    'payload'    => json_encode(['imm_id' => $row['imm_id'], 'type' => $row['type']]),
                    'actor'      => $row['publisher_ens'] ?? 'publisher',
                ]);
            }
        }

        // 4. New mirror (5%) for an existing address entry.
        if (Seeds::chance(0.05)) {
            $candidates = array_values(array_filter($entryRefs, fn ($e) => $e['type'] === 'address'));
            if ($candidates !== []) {
                $pick = Seeds::pick($candidates);
                $chains = ['sepolia' => 11155111, 'base' => 8453, 'arbitrum' => 42161, 'optimism' => 10];
                $chainName = (string) array_rand($chains);
                $row = [
                    'entry_id'        => $pick['id'],
                    'chain_id'        => $chains[$chainName],
                    'chain_name'      => $chainName,
                    'mirror_tx_hash'  => '\\x' . bin2hex(random_bytes(32)),
                    'mirrored_at'     => gmdate('Y-m-d H:i:sP'),
                    'status'          => 'active',
                    'relayer_address' => '\\x' . bin2hex(random_bytes(20)),
                ];
                try {
                    $mirrorBroker->insert($row);
                    $tally['mirrors']++;
                    $activityBroker->insert([
                        'event_type' => 'mirrored',
                        'entry_id'   => $pick['id'],
                        'payload'    => json_encode(['chain' => $chainName]),
                        'actor'      => 'relayer',
                    ]);
                } catch (Throwable) {
                    // Unique-active conflict on (entry_id, chain_id) - already mirrored to this chain.
                }
            }
        }

        // 5. Heartbeat refresh: refresh 95% of known agents to "now".
        $heartbeats = $heartbeatBroker->findAll(100);
        foreach ($heartbeats as $h) {
            if (Seeds::chance(0.05)) {
                continue;
            }
            $heartbeatBroker->upsert([
                'agent_id'    => $h->agent_id,
                'agent_ens'   => $h->agent_ens,
                'agent_role'  => $h->agent_role,
                'peer_count'  => max(0, (int) $h->peer_count + Seeds::int(-2, 2)),
                'version'     => $h->version,
                'metadata'    => '{}',
            ]);
            $tally['heartbeats']++;
        }

        // 6. Network stat snapshot per metric (gentle drift from current).
        foreach (StatTimeSeriesFactory::TARGETS as $metric => $target) {
            $latest = $statBroker->latestByMetric($metric);
            $current = $latest === null ? $target : (float) $latest->value;
            $jitter = $target * 0.005 * (Seeds::float() - 0.5);
            $value = max(0.0, $current + $jitter);
            $statBroker->insert([
                'metric'      => $metric,
                'value'       => sprintf('%.6f', $value),
                'captured_at' => gmdate('Y-m-d H:i:sP'),
            ]);
            $tally['network_stats']++;
        }

        // Quietly recompute publisher aggregates so the explorer stays consistent.
        $publisherBroker->recomputeAggregates();

        return $tally;
    }

    private function nextImmId(EntryBroker $broker): string
    {
        // Find max imm_id numeric suffix and increment.
        $row = $broker->findRecent(1);
        if ($row === []) {
            return 'IMM-2026-0001';
        }
        $immId = $row[0]->imm_id;
        if (preg_match('/^IMM-(\d+)-(\d+)$/', $immId, $m)) {
            $next = ((int) $m[2]) + 1;
            return sprintf('IMM-%s-%04d', $m[1], $next);
        }
        return 'IMM-2026-' . sprintf('%04d', random_int(10_000, 99_999));
    }
}
