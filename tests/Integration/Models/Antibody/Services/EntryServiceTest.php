<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Antibody\Services;

use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Antibody\Entities\Entry;
use App\Models\Antibody\Services\EntryService;
use Tests\IntegrationTestCase;

final class EntryServiceTest extends IntegrationTestCase
{
    private EntryService $service;
    private EntryBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new EntryBroker($this->db);
        $this->service = new EntryService($this->broker);
    }

    public function testFindByImmIdHydratesEntity(): void
    {
        $this->broker->insert($this->fixture('IMM-2026-0001'));

        $entry = $this->service->findByImmId('IMM-2026-0001');

        $this->assertInstanceOf(Entry::class, $entry);
        $this->assertSame('IMM-2026-0001', $entry->imm_id);
        $this->assertSame('address', $entry->type);
        $this->assertTrue($entry->isActive());
        $this->assertTrue($entry->isMalicious());
        $this->assertFalse($entry->isExpired());
    }

    public function testFindByImmIdReturnsNullForUnknown(): void
    {
        $this->assertNull($this->service->findByImmId('IMM-DOES-NOT-EXIST'));
    }

    public function testFindRecentReturnsEntities(): void
    {
        $this->broker->insert($this->fixture('IMM-A'));
        $this->broker->insert($this->fixture('IMM-B'));

        $entries = $this->service->findRecent(10);

        $this->assertCount(2, $entries);
        $this->assertContainsOnlyInstancesOf(Entry::class, $entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $immId): array
    {
        $hex = '\\x' . hash('sha256', $immId);
        return [
            'keccak_id'        => $hex,
            'imm_id'           => $immId,
            'type'             => 'address',
            'verdict'          => 'malicious',
            'confidence'       => 85,
            'severity'         => 70,
            'status'           => 'active',
            'primary_matcher'  => '{"address":"0xdeadbeef"}',
            'context_hash'     => $hex,
            'evidence_cid'     => $hex,
            'stake_lock_until' => '2026-12-31 00:00:00+00',
            'publisher'        => '\\x' . substr(hash('sha256', $immId), 0, 40),
            'stake_amount'     => '1.000000',
            'attestation'      => $hex,
        ];
    }
}
