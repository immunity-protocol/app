<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Event\Brokers;

use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Event\Brokers\BlockEventBroker;
use App\Models\Event\Brokers\CheckEventBroker;
use Tests\IntegrationTestCase;

final class BlockEventBrokerTest extends IntegrationTestCase
{
    private BlockEventBroker $broker;
    private CheckEventBroker $checks;
    private EntryBroker $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new BlockEventBroker($this->db);
        $this->checks = new CheckEventBroker($this->db);
        $this->entries = new EntryBroker($this->db);
    }

    public function testInsertAndFindRecent(): void
    {
        $entryId = $this->seedEntry('IMM-A');
        $a = $this->insertBlock($entryId, '100.000000');
        $b = $this->insertBlock($entryId, '500.000000');

        $recent = $this->broker->findRecent(10);

        $this->assertCount(2, $recent);
        $this->assertSame($b, (int) $recent[0]->id);
        $this->assertSame($a, (int) $recent[1]->id);
        $this->assertSame('500.000000', $recent[0]->value_protected_usd);
    }

    public function testSumValueProtectedAllTime(): void
    {
        $entryId = $this->seedEntry('IMM-A');
        $this->insertBlock($entryId, '100.000000');
        $this->insertBlock($entryId, '250.500000');
        $this->insertBlock($entryId, '49.500000');

        $this->assertSame('400.000000', $this->broker->sumValueProtectedAllTime());
    }

    public function testSumValueProtectedSinceFiltersByTime(): void
    {
        $entryId = $this->seedEntry('IMM-A');
        $this->insertBlock($entryId, '100.000000', '2025-01-01 00:00:00+00');
        $this->insertBlock($entryId, '300.000000', '2026-04-01 00:00:00+00');

        $this->assertSame('300.000000', $this->broker->sumValueProtectedSince('2026-01-01T00:00:00Z'));
    }

    private function seedEntry(string $immId): int
    {
        $hex = '\\x' . hash('sha256', $immId);
        return $this->entries->insert([
            'keccak_id'        => $hex,
            'imm_id'           => $immId,
            'type'             => 'address',
            'verdict'          => 'malicious',
            'confidence'       => 80,
            'severity'         => 60,
            'status'           => 'active',
            'primary_matcher'  => '{}',
            'context_hash'     => $hex,
            'evidence_cid'     => $hex,
            'stake_lock_until' => '2026-12-31 00:00:00+00',
            'publisher'        => '\\x' . substr(hash('sha256', $immId), 0, 40),
            'stake_amount'     => '1.000000',
            'attestation'      => $hex,
        ]);
    }

    private function insertBlock(int $entryId, string $value, string $occurredAt = '2026-04-25 12:00:00+00'): int
    {
        $checkId = $this->checks->insert([
            'agent_id'    => 'huntress-01',
            'tx_kind'     => 'erc20_transfer',
            'chain_id'    => 1,
            'decision'    => 'block',
            'cache_hit'   => 'true',
            'tee_used'    => 'false',
            'occurred_at' => $occurredAt,
        ]);
        return $this->broker->insert([
            'check_event_id'      => $checkId,
            'entry_id'            => $entryId,
            'agent_id'            => 'huntress-01',
            'value_protected_usd' => $value,
            'chain_id'            => 1,
            'occurred_at'         => $occurredAt,
        ]);
    }
}
