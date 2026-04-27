<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Event\Brokers;

use App\Models\Event\Brokers\CheckEventBroker;
use Tests\IntegrationTestCase;

/**
 * Covers CheckEventBroker::setValueAtRisk and propagation to block_event.
 */
final class CheckEventValueAtRiskTest extends IntegrationTestCase
{
    private CheckEventBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new CheckEventBroker($this->db);
    }

    public function testSetValueAtRiskUpdatesMatchingRows(): void
    {
        $tx = '0x' . str_repeat('a', 64);
        $checkId = $this->insertEvent($tx);

        $updated = $this->broker->setValueAtRisk($tx, '500.50');
        self::assertSame(1, $updated);

        $row = $this->db->query(
            "SELECT value_at_risk_usd FROM event.check_event WHERE id = ?",
            [$checkId]
        )->fetch();
        self::assertSame('500.500000', $row->value_at_risk_usd);
    }

    public function testSetValueAtRiskOutOfOrderReturnsZero(): void
    {
        $tx = '0x' . str_repeat('b', 64);
        $updated = $this->broker->setValueAtRisk($tx, '100.00');
        self::assertSame(0, $updated, 'no check_event yet for this tx');
    }

    public function testSetValueAtRiskPropagatesToBlockEvent(): void
    {
        $tx = '0x' . str_repeat('c', 64);
        $entryId = $this->insertEntry();
        $checkId = $this->insertEvent($tx);
        $this->insertBlockEvent($checkId, $entryId, $tx);

        $updated = $this->broker->setValueAtRisk($tx, '12345.67');
        self::assertSame(1, $updated);

        $blockRow = $this->db->query(
            "SELECT value_protected_usd FROM event.block_event WHERE tx_hash = decode(?, 'hex')",
            [str_repeat('c', 64)]
        )->fetch();
        self::assertNotFalse($blockRow);
        self::assertSame('12345.670000', $blockRow->value_protected_usd);
    }

    public function testSetValueAtRiskIsIdempotent(): void
    {
        $tx = '0x' . str_repeat('d', 64);
        $this->insertEvent($tx);

        $first = $this->broker->setValueAtRisk($tx, '50.00');
        $second = $this->broker->setValueAtRisk($tx, '50.00');
        self::assertSame(1, $first);
        self::assertSame(1, $second);
    }

    public function testSetValueAtRiskHandlesUppercaseHex(): void
    {
        $tx = '0x' . str_repeat('A', 64);
        $checkId = $this->insertEvent('0x' . str_repeat('a', 64));
        $updated = $this->broker->setValueAtRisk($tx, '99.00');
        self::assertSame(1, $updated, 'uppercase hex should normalize');
        unset($checkId);
    }

    private function insertEvent(string $txHash): int
    {
        $hex = ltrim(strtolower($txHash), '0x');
        return $this->broker->insert([
            'agent_id'    => '0xagent',
            'tx_kind'     => 'unknown',
            'chain_id'    => 16602,
            'decision'    => 'block',
            'cache_hit'   => 'true',
            'tee_used'    => 'false',
            'occurred_at' => '2026-04-26 12:00:00+00',
            'tx_hash'     => '\\x' . $hex,
            'log_index'   => 0,
        ]);
    }

    private function insertEntry(): int
    {
        $stub = '\\x' . str_repeat('e', 64);
        $pub = '\\x' . str_repeat('1', 40);
        $row = $this->db->query(
            "INSERT INTO antibody.entry (
                keccak_id, imm_id, type, verdict, confidence, severity, status,
                primary_matcher, context_hash, evidence_cid,
                stake_lock_until, publisher, stake_amount, attestation
             ) VALUES (
                ?, 'IMM-T', 'address'::antibody.entry_type, 'malicious'::antibody.entry_verdict,
                80, 70, 'active'::antibody.entry_status,
                '{}'::jsonb, ?, ?,
                '2026-12-31 00:00:00+00', ?, '1.000000', ?
             ) RETURNING id",
            [$stub, $stub, $stub, $pub, $stub]
        )->fetch();
        return (int) $row->id;
    }

    private function insertBlockEvent(int $checkId, int $entryId, string $txHash): void
    {
        $hex = ltrim(strtolower($txHash), '0x');
        $this->db->query(
            "INSERT INTO event.block_event
                (check_event_id, entry_id, agent_id, value_protected_usd,
                 chain_id, occurred_at, tx_hash, log_index)
             VALUES (?, ?, '0xagent', 0, 16602, now(), ?, 1)",
            [$checkId, $entryId, '\\x' . $hex]
        );
    }
}
