<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Antibody\Brokers;

use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Core\NetworkConfig;
use Tests\IntegrationTestCase;

/**
 * Covers EntryBroker::impactFor — per-antibody aggregates over event tables.
 */
final class EntryBrokerImpactTest extends IntegrationTestCase
{
    private EntryBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new EntryBroker($this->db);
    }

    public function testImpactWithNoEvents(): void
    {
        $entryId = $this->insertEntry('IMM-EMPTY');
        $impact = $this->broker->impactFor($entryId);

        self::assertSame(0, $impact['cache_hits']);
        self::assertSame(0, $impact['agents_synced']);
        self::assertSame(0, $impact['blocks_made']);
        self::assertSame('0', $impact['value_protected_usd']);
        self::assertCount(30, $impact['ingestion']);
        self::assertSame(0, array_sum($impact['ingestion']));
    }

    public function testImpactCountsCacheHitsAndDistinctAgents(): void
    {
        $entryId = $this->insertEntry('IMM-A');
        $this->insertCheck($entryId, 'agent-1', cacheHit: true);
        $this->insertCheck($entryId, 'agent-2', cacheHit: true);
        $this->insertCheck($entryId, 'agent-1', cacheHit: true);   // dup agent
        $this->insertCheck($entryId, 'agent-3', cacheHit: false);  // miss

        $impact = $this->broker->impactFor($entryId);

        self::assertSame(3, $impact['cache_hits']);
        self::assertSame(3, $impact['agents_synced']);
    }

    public function testImpactSumsValueProtectedAcrossBlocks(): void
    {
        $entryId = $this->insertEntry('IMM-B');
        $checkId1 = $this->insertCheck($entryId, 'agent-1', cacheHit: true);
        $checkId2 = $this->insertCheck($entryId, 'agent-2', cacheHit: true);
        $this->insertBlock($checkId1, $entryId, '100.00');
        $this->insertBlock($checkId2, $entryId, '250.50');

        $impact = $this->broker->impactFor($entryId);

        self::assertSame(2, $impact['blocks_made']);
        self::assertSame('350.500000', $impact['value_protected_usd']);
    }

    public function testImpactScopesToEntry(): void
    {
        $entryA = $this->insertEntry('IMM-A');
        $entryB = $this->insertEntry('IMM-B');
        $this->insertCheck($entryA, 'agent-1', cacheHit: true);
        $this->insertCheck($entryB, 'agent-2', cacheHit: true);

        $impactA = $this->broker->impactFor($entryA);
        $impactB = $this->broker->impactFor($entryB);

        self::assertSame(1, $impactA['cache_hits']);
        self::assertSame(1, $impactB['cache_hits']);
    }

    private function insertEntry(string $immId): int
    {
        $stub = '\\x' . hash('sha256', $immId);
        $pub = '\\x' . substr(hash('sha256', $immId), 0, 40);
        return $this->broker->insert([
            'keccak_id'         => $stub,
            'imm_id'            => $immId,
            'type'              => 'address',
            'verdict'           => 'malicious',
            'confidence'        => 80,
            'severity'          => 70,
            'status'            => 'active',
            'primary_matcher'   => '{}',
            'context_hash'      => $stub,
            'evidence_cid'      => $stub,
            'stake_lock_until'  => '2026-12-31 00:00:00+00',
            'publisher'         => $pub,
            'stake_amount'      => '1.000000',
            'attestation'       => $stub,
        ]);
    }

    private function insertCheck(int $entryId, string $agent, bool $cacheHit): int
    {
        $row = $this->db->query(
            "INSERT INTO event.check_event (
                agent_id, tx_kind, chain_id, decision, matched_entry_id,
                cache_hit, tee_used, occurred_at
             ) VALUES (
                ?, 'unknown', ?, 'block'::event.check_decision, ?,
                ?, false, now()
             ) RETURNING id",
            [$agent, NetworkConfig::galileo()->chainId, $entryId, $cacheHit ? 'true' : 'false']
        )->fetch();
        return (int) $row->id;
    }

    private function insertBlock(int $checkId, int $entryId, string $valueUsd): void
    {
        $this->db->query(
            "INSERT INTO event.block_event (
                check_event_id, entry_id, agent_id, value_protected_usd,
                chain_id, occurred_at
             ) VALUES (?, ?, 'agent', ?::numeric(20, 6), ?, now())",
            [$checkId, $entryId, $valueUsd, NetworkConfig::galileo()->chainId]
        );
    }
}
