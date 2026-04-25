<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Antibody\Brokers;

use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Antibody\Brokers\MirrorBroker;
use Tests\IntegrationTestCase;

final class MirrorBrokerTest extends IntegrationTestCase
{
    private MirrorBroker $broker;
    private EntryBroker $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new MirrorBroker($this->db);
        $this->entries = new EntryBroker($this->db);
    }

    public function testInsertAndFindByEntryId(): void
    {
        $entryId = $this->seedEntry('IMM-A');

        $this->broker->insert($this->fixture($entryId, 11155111, 'sepolia'));
        $this->broker->insert($this->fixture($entryId, 8453, 'base'));

        $mirrors = $this->broker->findByEntryId($entryId);

        $this->assertCount(2, $mirrors);
        $chains = array_column($mirrors, 'chain_name');
        $this->assertContains('sepolia', $chains);
        $this->assertContains('base', $chains);
    }

    public function testCountActiveIgnoresRemoved(): void
    {
        $entryId = $this->seedEntry('IMM-B');
        $this->broker->insert($this->fixture($entryId, 1, 'mainnet', status: 'active'));
        $this->broker->insert($this->fixture($entryId, 8453, 'base', status: 'removed'));

        $this->assertSame(1, $this->broker->countActive());
    }

    public function testCountActiveByChainGroups(): void
    {
        $a = $this->seedEntry('IMM-A');
        $b = $this->seedEntry('IMM-B');
        $this->broker->insert($this->fixture($a, 11155111, 'sepolia'));
        $this->broker->insert($this->fixture($b, 11155111, 'sepolia'));
        $this->broker->insert($this->fixture($a, 8453, 'base'));

        $counts = $this->broker->countActiveByChain();

        $this->assertSame(2, $counts['sepolia']);
        $this->assertSame(1, $counts['base']);
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
            'primary_matcher'  => '{"address":"0x"}',
            'context_hash'     => $hex,
            'evidence_cid'     => $hex,
            'stake_lock_until' => '2026-12-31 00:00:00+00',
            'publisher'        => '\\x' . substr(hash('sha256', $immId), 0, 40),
            'stake_amount'     => '1.000000',
            'attestation'      => $hex,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(int $entryId, int $chainId, string $chainName, string $status = 'active'): array
    {
        $hex = '\\x' . hash('sha256', "$entryId-$chainId");
        return [
            'entry_id'        => $entryId,
            'chain_id'        => $chainId,
            'chain_name'      => $chainName,
            'mirror_tx_hash'  => $hex,
            'status'          => $status,
            'relayer_address' => '\\x' . substr($hex, 2, 40),
        ];
    }
}
