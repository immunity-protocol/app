<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Indexer\Handlers;

use App\Models\Indexer\Brokers\HydrationQueueBroker;
use App\Models\Indexer\Handlers\AntibodyPublishedHandler;
use App\Models\Indexer\Handlers\AntibodySlashedHandler;
use App\Models\Indexer\Handlers\BondLedgerHandler;
use App\Models\Indexer\Handlers\MaturedHandler;
use App\Models\Indexer\Handlers\PublisherIdentityHandler;
use App\Models\Indexer\Handlers\ReputationHandler;
use Tests\IntegrationTestCase;

/**
 * End-to-end coverage of the Phase 1 Base handlers against the bond-model
 * schema: publish → probation, mature → active, slash, reputation mirror,
 * publisher identity, and the replay-safe bond/fee ledger.
 */
final class PhaseOneHandlersTest extends IntegrationTestCase
{
    private const KECCAK = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';
    private const PUBLISHER = 'b30af804fd19565e6bcbfdced944fdf654e585d9';
    private const EVIDENCE = '840cee1be318d9428e10f2af7dae0147efd1eede3a4d32610e7780a7a09479e9';

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function published(array $overrides = []): array
    {
        $args = array_merge([
            'keccakId'           => '0x' . self::KECCAK,
            'immSeq'             => 1,
            'publisher'          => '0x' . self::PUBLISHER,
            'abType'             => 0,
            'flavor'             => 0,
            'verdict'            => 0,
            'severity'           => 70,
            'confidence'         => 85,
            'reviewer'           => '0x' . str_repeat('0', 40),
            'primaryMatcherHash' => '0x' . str_repeat('0', 64),
            'evidenceCid'        => '0x' . self::EVIDENCE,
            'contextHash'        => '0x' . str_repeat('0', 64),
            'embeddingHash'      => '0x' . str_repeat('0', 64),
            'attestation'        => '0x' . str_repeat('cc', 32),
            'bond'               => '5000000',
            'expiresAt'          => '1900000000',
            'createdAt'          => '1745000000',
            'isSeeded'           => false,
        ], $overrides);

        return [
            'contract' => 'Registry', 'event' => 'Published', 'args' => $args,
            'blockNumber' => 42781200, 'txHash' => '0x' . str_repeat('1', 64),
            'logIndex' => 0, 'address' => '0x' . str_repeat('a', 40),
        ];
    }

    /** @return array<string,mixed> */
    private function event(string $contract, string $event, array $args, int $logIndex = 0, string $tx = '2'): array
    {
        return [
            'contract' => $contract, 'event' => $event, 'args' => $args,
            'blockNumber' => 42781300, 'txHash' => '0x' . str_repeat($tx, 64),
            'logIndex' => $logIndex, 'address' => '0x' . str_repeat('a', 40),
        ];
    }

    private function entry(): ?object
    {
        $row = $this->db->query(
            'SELECT * FROM antibody.entry WHERE keccak_id = ?',
            ['\\x' . self::KECCAK]
        )->fetch(\PDO::FETCH_OBJ);
        return $row === false ? null : $row;
    }

    private function publisher(): ?object
    {
        $row = $this->db->query(
            'SELECT * FROM antibody.publisher WHERE address = ?',
            ['\\x' . self::PUBLISHER]
        )->fetch(\PDO::FETCH_OBJ);
        return $row === false ? null : $row;
    }

    public function testPublishedInsertsProbationEntryAndQueuesHydration(): void
    {
        $handler = new AntibodyPublishedHandler($this->db, new HydrationQueueBroker($this->db));
        self::assertTrue($handler->handle($this->published()));

        $entry = $this->entry();
        self::assertNotNull($entry);
        self::assertSame('probation', $entry->status);
        self::assertSame('5.000000', $entry->bond_amount);
        self::assertSame(0, (int) $entry->is_seeded);
        self::assertNull($entry->matured_at);

        $queued = $this->db->query(
            'SELECT count(*) AS n FROM indexer.hydration_queue WHERE antibody_keccak_id = ?',
            ['\\x' . self::KECCAK]
        )->fetch(\PDO::FETCH_OBJ);
        self::assertSame(1, (int) $queued->n);

        $pub = $this->publisher();
        self::assertNotNull($pub);
        self::assertSame(1, (int) $pub->antibodies_published);
    }

    public function testSeededPublishInsertsActiveEntry(): void
    {
        $handler = new AntibodyPublishedHandler($this->db, new HydrationQueueBroker($this->db));
        $handler->handle($this->published(['isSeeded' => true, 'bond' => '0']));
        self::assertSame('active', $this->entry()->status);
        self::assertSame(1, (int) $this->entry()->is_seeded);
    }

    public function testMaturedPromotesProbationToActive(): void
    {
        (new AntibodyPublishedHandler($this->db, new HydrationQueueBroker($this->db)))->handle($this->published());
        $matured = new MaturedHandler($this->db);
        self::assertTrue($matured->handle($this->event('Registry', 'Matured', [
            'keccakId' => '0x' . self::KECCAK, 'publisher' => '0x' . self::PUBLISHER,
            'releasedFees' => '900000', 'maturedAt' => '1745100000',
        ])));
        self::assertSame('active', $this->entry()->status);
        self::assertNotNull($this->entry()->matured_at);
    }

    public function testSlashedMarksEntryAndPublisher(): void
    {
        (new AntibodyPublishedHandler($this->db, new HydrationQueueBroker($this->db)))->handle($this->published());
        $slashed = new AntibodySlashedHandler($this->db);
        self::assertTrue($slashed->handle($this->event('Registry', 'Slashed', [
            'keccakId' => '0x' . self::KECCAK, 'publisher' => '0x' . self::PUBLISHER,
            'challenger' => '0x' . str_repeat('c', 40), 'bondForfeited' => '5000000', 'escrowClawedBack' => '0',
        ])));
        self::assertSame('slashed', $this->entry()->status);
        self::assertSame(1, (int) $this->publisher()->slashed_count);
    }

    public function testReputationMaturedUpdatesScoreAndIsReplaySafe(): void
    {
        $rep = new ReputationHandler($this->db);
        $ev = $this->event('Reputation', 'Matured', [
            'publisher' => '0x' . self::PUBLISHER, 'newScore' => '125',
        ]);
        self::assertTrue($rep->handleMatured($ev));
        self::assertFalse($rep->handleMatured($ev)); // replay: same (tx, logIndex)

        $pub = $this->publisher();
        self::assertSame('125.000000', $pub->score);
        self::assertSame(1, (int) $pub->matured_count);
    }

    public function testGenesisGrantedSetsGrant(): void
    {
        $rep = new ReputationHandler($this->db);
        self::assertTrue($rep->handleGenesisGranted($this->event('Reputation', 'GenesisGranted', [
            'publisher' => '0x' . self::PUBLISHER, 'amount' => '100', 'newScore' => '100',
        ])));
        $pub = $this->publisher();
        self::assertSame('100.000000', $pub->score);
        self::assertSame('100.000000', $pub->genesis_granted);
    }

    public function testRegisteredCreatesIdentity(): void
    {
        $identity = new PublisherIdentityHandler($this->db);
        self::assertTrue($identity->handleRegistered($this->event('PublisherRegistrar', 'Registered', [
            'publisher' => '0x' . self::PUBLISHER, 'node' => '0x' . str_repeat('11', 32),
            'label' => 'alice', 'bond' => '2000000',
        ])));
        $pub = $this->publisher();
        self::assertSame('alice.immunity.eth', $pub->ens);
        self::assertSame('2.000000', $pub->registration_bond);
        self::assertFalse((bool) $pub->deregistered);
    }

    public function testFeesEscrowedAccruesAndIsReplaySafe(): void
    {
        (new AntibodyPublishedHandler($this->db, new HydrationQueueBroker($this->db)))->handle($this->published());
        $ledger = new BondLedgerHandler($this->db);
        $ev = $this->event('Registry', 'FeesEscrowed', [
            'keccakId' => '0x' . self::KECCAK, 'publisher' => '0x' . self::PUBLISHER, 'amount' => '1600',
        ], 3);
        self::assertTrue($ledger->handleFeesEscrowed($ev));
        self::assertFalse($ledger->handleFeesEscrowed($ev)); // replay guard
        self::assertSame('0.001600', $this->entry()->escrowed_fees);
    }
}
