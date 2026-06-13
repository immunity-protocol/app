<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Indexer\Handlers;

use App\Models\Indexer\Handlers\ThreatHandler;
use Tests\IntegrationTestCase;

/**
 * Threat assignment: the first antibody seen for a primary_matcher_hash claims a
 * stable, sequential, CVE-style threat id (IMM-T-YYYY-NNNN). Corroborating
 * antibodies for the same matcher link to the existing threat — no renumber, no
 * duplicate. Stable across re-runs (idempotent).
 */
final class ThreatTest extends IntegrationTestCase
{
    private const MATCHER_A = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const MATCHER_B = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';

    /** @return array<string,mixed> */
    private function published(string $matcher, int $createdAt): array
    {
        return ['args' => ['primaryMatcherHash' => '0x' . $matcher, 'createdAt' => (string) $createdAt]];
    }

    private function threatIdFor(string $matcher): ?string
    {
        $row = $this->db->query(
            "SELECT threat_id FROM antibody.threat WHERE matcher_hash = ?",
            ['\\x' . $matcher]
        )->fetch(\PDO::FETCH_OBJ);
        return $row === false ? null : (string) $row->threat_id;
    }

    private function threatCount(): int
    {
        return (int) $this->db->query("SELECT count(*) AS n FROM antibody.threat")
            ->fetch(\PDO::FETCH_OBJ)->n;
    }

    public function testFirstSightingAssignsSequentialCveId(): void
    {
        $handler = new ThreatHandler($this->db);

        self::assertTrue($handler->handlePublished($this->published(self::MATCHER_A, 1767225600)));
        self::assertTrue($handler->handlePublished($this->published(self::MATCHER_B, 1767225700)));

        // Year + zero-padded serial; the bigserial advances by exactly one
        // between two fresh matchers. (Absolute number depends on prior
        // sequence state — bigserial is not rolled back with the transaction —
        // so assert the format and the +1 monotonic step, not a fixed 0001.)
        $idA = $this->threatIdFor(self::MATCHER_A);
        $idB = $this->threatIdFor(self::MATCHER_B);
        self::assertMatchesRegularExpression('/^IMM-T-2026-\d{4,}$/', (string) $idA);
        self::assertMatchesRegularExpression('/^IMM-T-2026-\d{4,}$/', (string) $idB);

        $seqA = (int) substr((string) $idA, (int) strrpos((string) $idA, '-') + 1);
        $seqB = (int) substr((string) $idB, (int) strrpos((string) $idB, '-') + 1);
        self::assertSame($seqA + 1, $seqB, 'second fresh matcher gets the next serial');
        self::assertSame(2, $this->threatCount());
    }

    public function testCorroboratingAntibodiesLinkToSameThreatNoRenumber(): void
    {
        $handler = new ThreatHandler($this->db);

        // First publisher mints the threat.
        self::assertTrue($handler->handlePublished($this->published(self::MATCHER_A, 1767225600)));
        $id = $this->threatIdFor(self::MATCHER_A);

        // Two more corroborators for the SAME matcher → no new threat, same id.
        self::assertFalse($handler->handlePublished($this->published(self::MATCHER_A, 1767225900)));
        self::assertFalse($handler->handlePublished($this->published(self::MATCHER_A, 1767226200)));

        self::assertSame($id, $this->threatIdFor(self::MATCHER_A));
        self::assertSame(1, $this->threatCount(), 'corroboration does not create extra threats');
    }

    public function testReplayIsIdempotentAndStable(): void
    {
        $handler = new ThreatHandler($this->db);

        $handler->handlePublished($this->published(self::MATCHER_A, 1767225600));
        $first = $this->threatIdFor(self::MATCHER_A);

        // Re-run the very same event (backfill replay): id unchanged, no dupes.
        self::assertFalse($handler->handlePublished($this->published(self::MATCHER_A, 1767225600)));
        self::assertSame($first, $this->threatIdFor(self::MATCHER_A));
        self::assertSame(1, $this->threatCount());
    }

    public function testZeroHashIsIgnored(): void
    {
        $handler = new ThreatHandler($this->db);
        self::assertFalse($handler->handlePublished($this->published(str_repeat('0', 64), 1767225600)));
        self::assertSame(0, $this->threatCount());
    }
}
