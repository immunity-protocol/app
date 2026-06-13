<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Indexer\Handlers;

use App\Models\Indexer\Brokers\HydrationQueueBroker;
use App\Models\Indexer\Handlers\AntibodyPublishedHandler;
use App\Models\Indexer\Handlers\AntibodySlashedHandler;
use App\Models\Indexer\Handlers\CorroborationHandler;
use Tests\IntegrationTestCase;

/**
 * Corroboration: several distinct publishers each mint their own antibody (own
 * keccak_id) for the SAME primary_matcher_hash. With the matcher-hash index now
 * non-unique, all corroborating antibodies must persist (the Published handler
 * no longer throws on the 2nd/3rd) and corroboration_count reflects the distinct
 * publisher count.
 */
final class CorroborationTest extends IntegrationTestCase
{
    private const MATCHER = 'c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5';

    /** @return array<string,mixed> */
    private function published(int $seq, string $keccak, string $publisher): array
    {
        return [
            'contract' => 'Registry', 'event' => 'Published',
            'args' => [
                'keccakId' => '0x' . $keccak, 'immSeq' => $seq, 'publisher' => '0x' . $publisher,
                'abType' => 0, 'flavor' => 0, 'verdict' => 0, 'severity' => 70, 'confidence' => 85,
                'reviewer' => '0x' . str_repeat('0', 40),
                'primaryMatcherHash' => '0x' . self::MATCHER,
                'evidenceCid' => '0x' . str_repeat('0', 64), 'contextHash' => '0x' . str_repeat('0', 64),
                'embeddingHash' => '0x' . str_repeat('0', 64), 'attestation' => '0x' . str_repeat('cc', 32),
                'bond' => '5000000', 'expiresAt' => '1900000000', 'createdAt' => (string) (1745000000 + $seq),
                'isSeeded' => false,
            ],
            'blockNumber' => 42781200 + $seq, 'txHash' => '0x' . str_pad((string) $seq, 64, '0', STR_PAD_LEFT),
            'logIndex' => 0, 'address' => '0x' . str_repeat('a', 40),
        ];
    }

    private function countForHash(): int
    {
        return (int) $this->db->query(
            "SELECT count(*) AS n FROM antibody.entry WHERE primary_matcher_hash = ?",
            ['\\x' . self::MATCHER]
        )->fetch(\PDO::FETCH_OBJ)->n;
    }

    private function corroborationForHash(): int
    {
        return (int) $this->db->query(
            "SELECT corroboration_count AS n FROM antibody.entry WHERE primary_matcher_hash = ? ORDER BY created_at ASC LIMIT 1",
            ['\\x' . self::MATCHER]
        )->fetch(\PDO::FETCH_OBJ)->n;
    }

    public function testThreeDistinctPublishersCorroborateSameMatcher(): void
    {
        $publish = new AntibodyPublishedHandler($this->db, new HydrationQueueBroker($this->db));
        $corro = new CorroborationHandler($this->db);

        $rows = [
            [1, str_repeat('a1', 32), str_repeat('11', 20)],
            [2, str_repeat('a2', 32), str_repeat('22', 20)],
            [3, str_repeat('a3', 32), str_repeat('33', 20)],
        ];
        foreach ($rows as [$seq, $keccak, $publisher]) {
            // Must NOT throw on the 2nd/3rd despite the shared matcher hash.
            self::assertTrue($publish->handle($this->published($seq, $keccak, $publisher)));
            $corro->handlePublished($this->published($seq, $keccak, $publisher));
        }

        self::assertSame(3, $this->countForHash(), 'all 3 corroborating antibodies persist');
        self::assertSame(3, $this->corroborationForHash(), 'corroboration_count == distinct publishers');
    }

    public function testSlashDropsACorroborator(): void
    {
        $publish = new AntibodyPublishedHandler($this->db, new HydrationQueueBroker($this->db));
        $corro = new CorroborationHandler($this->db);
        $rows = [
            [1, str_repeat('b1', 32), str_repeat('11', 20)],
            [2, str_repeat('b2', 32), str_repeat('22', 20)],
        ];
        foreach ($rows as [$seq, $keccak, $publisher]) {
            $publish->handle($this->published($seq, $keccak, $publisher));
            $corro->handlePublished($this->published($seq, $keccak, $publisher));
        }
        self::assertSame(2, $this->corroborationForHash());

        // Slash one antibody → its publisher drops out of the live count.
        $slash = new AntibodySlashedHandler($this->db);
        $slashEvent = [
            'contract' => 'Registry', 'event' => 'Slashed',
            'args' => [
                'keccakId' => '0x' . str_repeat('b1', 32), 'publisher' => '0x' . str_repeat('11', 20),
                'challenger' => '0x' . str_repeat('cc', 20), 'bondForfeited' => '5000000', 'escrowClawedBack' => '0',
            ],
            'blockNumber' => 42781500, 'txHash' => '0x' . str_repeat('9', 64), 'logIndex' => 0,
            'address' => '0x' . str_repeat('a', 40),
        ];
        $slash->handle($slashEvent);
        $corro->handleByKeccak($slashEvent);

        self::assertSame(1, $this->corroborationForHash(), 'slashed publisher no longer corroborates');
    }
}
