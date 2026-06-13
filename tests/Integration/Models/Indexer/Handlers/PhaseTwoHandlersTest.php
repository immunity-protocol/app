<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Indexer\Handlers;

use App\Models\Indexer\Handlers\ChallengeHandler;
use App\Models\Indexer\Handlers\EnsIngestHandler;
use App\Models\Indexer\Handlers\ProtectedSetHandler;
use Tests\IntegrationTestCase;

/**
 * Phase 2 ingestion: challenge/jury lifecycle, protected set, and the ENS
 * subname/text mirror.
 */
final class PhaseTwoHandlersTest extends IntegrationTestCase
{
    private function event(string $contract, string $event, array $args, int $logIndex = 0, string $tx = '7'): array
    {
        return [
            'contract' => $contract, 'event' => $event, 'args' => $args,
            'blockNumber' => 42781400, 'txHash' => '0x' . str_repeat($tx, 64),
            'logIndex' => $logIndex, 'address' => '0x' . str_repeat('a', 40),
        ];
    }

    private function insertEntry(string $keccak, string $publisher, ?string $matcherHash = null, string $status = 'active'): void
    {
        $this->db->query(
            "INSERT INTO antibody.entry
                (keccak_id, imm_id, type, verdict, confidence, severity, status,
                 primary_matcher, primary_matcher_hash, context_hash, evidence_cid,
                 publisher, bond_amount, attestation)
             VALUES (?, ?, 'address'::antibody.entry_type, 'malicious'::antibody.entry_verdict,
                 80, 70, ?::antibody.entry_status, '{}'::jsonb, ?, ?, ?, ?, '1.000000', ?)",
            [
                '\\x' . $keccak, 'IMM-2026-' . substr($keccak, 0, 4),
                $status,
                $matcherHash === null ? null : '\\x' . $matcherHash,
                '\\x' . str_repeat('cc', 32), '\\x' . str_repeat('ee', 32),
                '\\x' . $publisher, '\\x' . str_repeat('aa', 32),
            ]
        );
    }

    private function challenge(string $keccak): ?object
    {
        $row = $this->db->query('SELECT * FROM antibody.challenge WHERE keccak_id = ?', ['\\x' . $keccak])
            ->fetch(\PDO::FETCH_OBJ);
        return $row === false ? null : $row;
    }

    public function testChallengeLifecycle(): void
    {
        $keccak = str_repeat('1d', 32);
        $h = new ChallengeHandler($this->db);

        $h->handleVerdictRequested($this->event('ChallengeManager', 'VerdictRequested', [
            'antibodyId' => '0x' . $keccak, 'evidenceCid' => '0x' . str_repeat('bb', 32),
        ]));
        self::assertSame('LAYER1_PENDING', $this->challenge($keccak)->status);

        $h->handleVerdictReceived($this->event('CREVerdictReceiver', 'VerdictReceived', [
            'antibodyId' => '0x' . $keccak, 'invalidVotes' => 5, 'validVotes' => 2,
        ], 1));
        self::assertSame(5, (int) $this->challenge($keccak)->invalid_votes);

        $h->handleEscalated($this->event('ChallengeManager', 'Escalated', ['antibodyId' => '0x' . $keccak], 2));
        self::assertSame('LAYER2_ESCALATED', $this->challenge($keccak)->status);

        $h->handleResolved($this->event('ChallengeManager', 'Resolved', [
            'antibodyId' => '0x' . $keccak, 'invalid' => true, 'challenger' => '0x' . str_repeat('cd', 20),
            'winnerPayout' => '3000000', 'jurorFee' => '500000', 'treasuryAmount' => '100000',
        ], 3));
        $c = $this->challenge($keccak);
        self::assertSame('RESOLVED', $c->status);
        self::assertTrue((bool) $c->is_invalid);
        self::assertSame('3.000000', $c->winner_payout);
    }

    public function testChallengeOpenedAndTimedOutFlipEntryStatus(): void
    {
        $keccak = str_repeat('2d', 32);
        $this->insertEntry($keccak, str_repeat('b1', 20), status: 'active');
        $h = new ChallengeHandler($this->db);

        self::assertTrue($h->handleChallengeOpened($this->event('Registry', 'ChallengeOpened', ['keccakId' => '0x' . $keccak])));
        self::assertSame('challenged', $this->entryStatus($keccak));

        self::assertTrue($h->handleChallengeTimedOut($this->event('Registry', 'ChallengeTimedOut', [
            'keccakId' => '0x' . $keccak, 'publisher' => '0x' . str_repeat('b1', 20),
        ], 1)));
        self::assertSame('active', $this->entryStatus($keccak));
    }

    public function testProtectedSetUpsertAndToggle(): void
    {
        $h = new ProtectedSetHandler($this->db);
        $target = str_repeat('77', 20);
        $h->handle($this->event('ProtectedSet', 'ProtectedUpdated', ['target' => '0x' . $target, 'protected' => true]));
        self::assertTrue((bool) $this->protected($target));

        $h->handle($this->event('ProtectedSet', 'ProtectedUpdated', ['target' => '0x' . $target, 'protected' => false], 1));
        self::assertFalse((bool) $this->protected($target));
    }

    public function testEnsSubnameAndReputationTextMirror(): void
    {
        $owner = str_repeat('5a', 20);
        $node = str_repeat('33', 32);
        $h = new EnsIngestHandler($this->db);

        $h->handleSubnodeCreated($this->event('L2Registry', 'SubnodeCreated', [
            'node' => '0x' . $node, 'parentNode' => '0x' . str_repeat('00', 32),
            'label' => 'bob', 'owner' => '0x' . $owner,
        ]));
        $pub = $this->db->query('SELECT * FROM antibody.publisher WHERE address = ?', ['\\x' . $owner])->fetch(\PDO::FETCH_OBJ);
        self::assertSame('bob.immunity.eth', $pub->ens);

        self::assertTrue($h->handleTextChanged($this->event('L2Registry', 'TextChanged', [
            'node' => '0x' . $node, 'key' => 'immunity.reputation', 'value' => '142',
        ], 1)));
        $pub = $this->db->query('SELECT score FROM antibody.publisher WHERE address = ?', ['\\x' . $owner])->fetch(\PDO::FETCH_OBJ);
        self::assertSame('142.000000', $pub->score);
    }

    private function entryStatus(string $keccak): string
    {
        return (string) $this->db->query('SELECT status FROM antibody.entry WHERE keccak_id = ?', ['\\x' . $keccak])
            ->fetch(\PDO::FETCH_OBJ)->status;
    }

    private function protected(string $address): ?bool
    {
        $row = $this->db->query('SELECT protected FROM antibody.protected_target WHERE address = ?', ['\\x' . $address])
            ->fetch(\PDO::FETCH_OBJ);
        return $row === false ? null : (bool) $row->protected;
    }
}
