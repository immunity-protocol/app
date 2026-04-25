<?php

declare(strict_types=1);

namespace App\Models\Mock;

use App\Models\Agent\Brokers\HeartbeatBroker;
use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Antibody\Brokers\MirrorBroker;
use App\Models\Antibody\Brokers\PublisherBroker;
use App\Models\Event\Brokers\ActivityBroker;
use App\Models\Event\Brokers\BlockEventBroker;
use App\Models\Event\Brokers\CheckEventBroker;
use App\Models\Network\Brokers\StatBroker;
use Zephyrus\Data\Database;

/**
 * Composes mock factories in dependency order, writes everything to the DB,
 * and returns a tally for the CLI summary.
 *
 *   reset()         truncates all domain tables in FK-safe order
 *   seed()          generates + inserts a full mock dataset deterministically
 */
final class Orchestrator
{
    public function __construct(
        private readonly Database $db,
        private readonly int $seed = Seeds::DEFAULT_SEED,
        private readonly int $entryCount = 350,
        private readonly int $checkEventCount = 50_000,
        private readonly int $publisherCount = 80,
        private readonly int $heartbeatCount = 60,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function seed(): array
    {
        Seeds::reset($this->seed);

        $publisherFactory = new PublisherFactory($this->publisherCount);
        $entryFactory = new EntryFactory($publisherFactory, total: $this->entryCount);
        $mirrorFactory = new MirrorFactory();
        $checkFactory = new CheckEventFactory(total: $this->checkEventCount);
        $heartbeatFactory = new HeartbeatFactory($this->heartbeatCount);
        $statFactory = new StatTimeSeriesFactory();
        $activityFactory = new ActivityFactory();

        $publisherBroker = new PublisherBroker($this->db);
        $entryBroker = new EntryBroker($this->db);
        $mirrorBroker = new MirrorBroker($this->db);
        $checkBroker = new CheckEventBroker($this->db);
        $blockBroker = new BlockEventBroker($this->db);
        $heartbeatBroker = new HeartbeatBroker($this->db);
        $statBroker = new StatBroker($this->db);
        $activityBroker = new ActivityBroker($this->db);

        $tally = [];

        // 1. Publishers (counts will be recomputed at the end).
        foreach ($publisherFactory->all() as $p) {
            $publisherBroker->upsert([
                'address'              => '\\x' . bin2hex($p['address']),
                'ens'                  => $p['ens'],
                'antibodies_published' => 0,
                'successful_blocks'    => 0,
                'total_earned_usdc'    => '0',
                'total_staked_usdc'    => '0',
            ]);
        }
        $tally['publishers'] = count($publisherFactory->all());

        // 2. Antibody entries. Track id + minimum metadata for downstream factories.
        $entryRefs = [];
        foreach ($entryFactory->generate() as $row) {
            $id = $entryBroker->insert($row);
            $entryRefs[] = [
                'id'             => $id,
                'imm_id'         => $row['imm_id'],
                'type'           => $row['type'],
                'publisher_ens'  => $row['publisher_ens'],
                'created_at'     => $row['created_at'],
            ];
        }
        $tally['entries'] = count($entryRefs);

        // 3. Mirrors.
        $mirrorRows = $mirrorFactory->generate(
            array_map(fn ($e) => ['id' => $e['id'], 'type' => $e['type'], 'created_at' => $e['created_at']], $entryRefs)
        );
        $mirrorRefs = [];
        foreach ($mirrorRows as $row) {
            $mirrorBroker->insert($row);
            $mirrorRefs[] = [
                'entry_id'    => $row['entry_id'],
                'chain_name'  => $row['chain_name'],
                'mirrored_at' => $row['mirrored_at'],
            ];
        }
        $tally['mirrors'] = count($mirrorRows);

        // 4. Check events + derived block events.
        $generated = $checkFactory->generate(
            array_map(fn ($e) => ['id' => $e['id'], 'type' => $e['type']], $entryRefs)
        );
        $checkIds = [];
        foreach ($generated['checks'] as $row) {
            $checkIds[] = $checkBroker->insert($row);
        }
        $tally['check_events'] = count($generated['checks']);

        $blockRefs = [];
        foreach ($generated['blockSpecs'] as $spec) {
            $checkEventId = $checkIds[$spec['check_index']];
            $row = CheckEventFactory::blockEventRow($checkEventId, $spec);
            $blockBroker->insert($row);
            $blockRefs[] = [
                'entry_id'           => $row['entry_id'],
                'value_protected_usd' => $row['value_protected_usd'],
                'agent_id'           => $row['agent_id'],
                'occurred_at'        => $row['occurred_at'],
            ];
        }
        $tally['block_events'] = count($blockRefs);

        // 5. Heartbeats.
        foreach ($heartbeatFactory->generate() as $row) {
            $heartbeatBroker->upsert($row);
        }
        $tally['heartbeats'] = $this->heartbeatCount;

        // 6. Network stat time series (bulk insert; this table grows fast).
        $statRows = $statFactory->generate();
        $statBroker->insertBulk($statRows);
        $tally['network_stats'] = count($statRows);

        // 7. Activity feed (derived; sort newest first).
        $activityRows = $activityFactory->generate($entryRefs, $mirrorRefs, $blockRefs);
        foreach ($activityRows as $row) {
            $activityBroker->insert($row);
        }
        $tally['activity'] = count($activityRows);

        // 8. Recompute publisher aggregates from entries + block events.
        $publisherBroker->recomputeAggregates();

        return $tally;
    }

    /**
     * Truncate every domain table in FK-safe order. Sequences reset.
     */
    public function reset(): void
    {
        $this->db->query(
            "TRUNCATE TABLE
                event.activity,
                event.block_event,
                event.check_event,
                antibody.mirror,
                antibody.entry,
                antibody.publisher,
                agent.heartbeat,
                network.stat
             RESTART IDENTITY CASCADE"
        );
    }
}
