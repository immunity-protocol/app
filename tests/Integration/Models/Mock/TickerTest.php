<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Mock;

use App\Models\Agent\Brokers\HeartbeatBroker;
use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Antibody\Brokers\PublisherBroker;
use App\Models\Mock\Ticker;
use Tests\IntegrationTestCase;

final class TickerTest extends IntegrationTestCase
{
    public function testRunAlwaysWritesNetworkStatsAndHeartbeats(): void
    {
        $this->seedSmall();
        $tally = (new Ticker($this->db))->run();

        $this->assertSame(5, $tally['network_stats']);
        $this->assertGreaterThan(0, $tally['heartbeats']);
    }

    private function seedSmall(): void
    {
        $heartbeats = new HeartbeatBroker($this->db);
        for ($i = 0; $i < 5; $i++) {
            $heartbeats->upsert([
                'agent_id'   => "agent-$i",
                'agent_role' => 'trader',
                'version'    => '0.1.0',
            ]);
        }
        $publisher = new PublisherBroker($this->db);
        $publisher->upsert([
            'address' => '\\x' . str_repeat('aa', 20),
            'ens'     => 'test.eth',
            'antibodies_published' => 0,
            'successful_blocks'    => 0,
            'total_earned_usdc'    => '0',
            'total_staked_usdc'    => '0',
        ]);
        $entry = new EntryBroker($this->db);
        $hex = '\\x' . hash('sha256', 'IMM-X');
        $entry->insert([
            'keccak_id'        => $hex,
            'imm_id'           => 'IMM-2026-0001',
            'type'             => 'address',
            'verdict'          => 'malicious',
            'confidence'       => 80,
            'severity'         => 60,
            'status'           => 'active',
            'primary_matcher'  => '{}',
            'context_hash'     => $hex,
            'evidence_cid'     => $hex,
            'stake_lock_until' => '2026-12-31 00:00:00+00',
            'publisher'        => '\\x' . str_repeat('aa', 20),
            'stake_amount'     => '1.000000',
            'attestation'      => $hex,
        ]);
    }
}
