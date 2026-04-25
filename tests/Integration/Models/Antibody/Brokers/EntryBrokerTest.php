<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Antibody\Brokers;

use App\Models\Antibody\Brokers\EntryBroker;
use Tests\IntegrationTestCase;

final class EntryBrokerTest extends IntegrationTestCase
{
    private EntryBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new EntryBroker($this->db);
    }

    public function testInsertAndFindByImmId(): void
    {
        $id = $this->broker->insert($this->fixture(immId: 'IMM-2026-0001'));

        $found = $this->broker->findByImmId('IMM-2026-0001');
        $this->assertNotNull($found);
        $this->assertSame($id, (int) $found->id);
        $this->assertSame('IMM-2026-0001', $found->imm_id);
        $this->assertSame('address', $found->type);
        $this->assertSame('malicious', $found->verdict);
        $this->assertSame('active', $found->status);
    }

    public function testFindByImmIdReturnsNullForUnknown(): void
    {
        $this->assertNull($this->broker->findByImmId('IMM-9999-9999'));
    }

    public function testFindRecentReturnsLatestFirst(): void
    {
        $a = $this->broker->insert($this->fixture(immId: 'IMM-A'));
        $b = $this->broker->insert($this->fixture(immId: 'IMM-B'));
        $c = $this->broker->insert($this->fixture(immId: 'IMM-C'));

        $recent = $this->broker->findRecent(2);

        $this->assertCount(2, $recent);
        $this->assertSame($c, (int) $recent[0]->id);
        $this->assertSame($b, (int) $recent[1]->id);
        unset($a);
    }

    public function testFindRecentSupportsKeysetPagination(): void
    {
        $a = $this->broker->insert($this->fixture(immId: 'IMM-A'));
        $b = $this->broker->insert($this->fixture(immId: 'IMM-B'));
        $c = $this->broker->insert($this->fixture(immId: 'IMM-C'));

        $page2 = $this->broker->findRecent(10, beforeId: $c);

        $this->assertCount(2, $page2);
        $this->assertSame($b, (int) $page2[0]->id);
        $this->assertSame($a, (int) $page2[1]->id);
    }

    public function testCountActiveIgnoresOtherStatuses(): void
    {
        $this->broker->insert($this->fixture(immId: 'IMM-A', status: 'active'));
        $this->broker->insert($this->fixture(immId: 'IMM-B', status: 'expired'));
        $this->broker->insert($this->fixture(immId: 'IMM-C', status: 'slashed'));

        $this->assertSame(1, $this->broker->countActive());
    }

    public function testCountByTypeBucketsRows(): void
    {
        $this->broker->insert($this->fixture(immId: 'IMM-A', type: 'address'));
        $this->broker->insert($this->fixture(immId: 'IMM-B', type: 'address'));
        $this->broker->insert($this->fixture(immId: 'IMM-C', type: 'semantic'));

        $counts = $this->broker->countByType();

        $this->assertSame(2, $counts['address']);
        $this->assertSame(1, $counts['semantic']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(
        string $immId = 'IMM-2026-0001',
        string $type = 'address',
        string $verdict = 'malicious',
        int $confidence = 85,
        int $severity = 70,
        string $status = 'active',
    ): array {
        $stubHex = '\\x' . hash('sha256', $immId);
        return [
            'keccak_id'         => $stubHex,
            'imm_id'            => $immId,
            'type'              => $type,
            'verdict'           => $verdict,
            'confidence'        => $confidence,
            'severity'          => $severity,
            'status'            => $status,
            'primary_matcher'   => '{"address":"0xdeadbeef"}',
            'context_hash'      => $stubHex,
            'evidence_cid'      => $stubHex,
            'stake_lock_until'  => '2026-12-31 00:00:00+00',
            'publisher'         => '\\x' . substr(hash('sha256', $immId), 0, 40),
            'stake_amount'      => '1.000000',
            'attestation'       => $stubHex,
        ];
    }
}
