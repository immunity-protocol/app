<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Antibody\Brokers;

use App\Models\Antibody\Brokers\EntryBroker;
use Tests\IntegrationTestCase;

/**
 * Covers the page-number filter API: findPage, countAll, countByStatus,
 * countByVerdict.
 */
final class EntryBrokerFilterTest extends IntegrationTestCase
{
    private EntryBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new EntryBroker($this->db);
    }

    public function testFindPageRespectsTypeFilter(): void
    {
        $this->seed('IMM-A', 'address');
        $this->seed('IMM-B', 'bytecode');
        $this->seed('IMM-C', 'address');

        $rows = $this->broker->findPage(types: ['address']);
        self::assertCount(2, $rows);
        self::assertSame('IMM-C', $rows[0]->imm_id);
        self::assertSame('IMM-A', $rows[1]->imm_id);
    }

    public function testFindPageRespectsMultipleTypes(): void
    {
        $this->seed('IMM-A', 'address');
        $this->seed('IMM-B', 'bytecode');
        $this->seed('IMM-C', 'graph');

        $rows = $this->broker->findPage(types: ['address', 'bytecode']);
        self::assertCount(2, $rows);
    }

    public function testFindPageRespectsStatusFilter(): void
    {
        $this->seed('IMM-A', status: 'active');
        $this->seed('IMM-B', status: 'expired');
        $this->seed('IMM-C', status: 'slashed');

        $rows = $this->broker->findPage(statuses: ['expired', 'slashed']);
        self::assertCount(2, $rows);
    }

    public function testFindPageRespectsVerdictFilter(): void
    {
        $this->seed('IMM-A', verdict: 'malicious');
        $this->seed('IMM-B', verdict: 'suspicious');

        $rows = $this->broker->findPage(verdicts: ['suspicious']);
        self::assertCount(1, $rows);
        self::assertSame('IMM-B', $rows[0]->imm_id);
    }

    public function testFindPageSearchMatchesImmId(): void
    {
        $this->seed('IMM-2026-0001');
        $this->seed('IMM-2026-0099');

        $rows = $this->broker->findPage(search: '0001');
        self::assertCount(1, $rows);
        self::assertSame('IMM-2026-0001', $rows[0]->imm_id);
    }

    public function testFindPageSeverityRange(): void
    {
        $this->seed('IMM-LOW', severity: 30);
        $this->seed('IMM-MID', severity: 60);
        $this->seed('IMM-HI',  severity: 95);

        $rows = $this->broker->findPage(sevMin: 50, sevMax: 80);
        self::assertCount(1, $rows);
        self::assertSame('IMM-MID', $rows[0]->imm_id);
    }

    public function testFindPagePublisherEnsExact(): void
    {
        $this->seed('IMM-A', publisherEns: 'alice.eth');
        $this->seed('IMM-B', publisherEns: 'bob.eth');

        $rows = $this->broker->findPage(publisher: 'alice.eth');
        self::assertCount(1, $rows);
        self::assertSame('IMM-A', $rows[0]->imm_id);
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->seed(sprintf('IMM-%02d', $i));
        }

        $page1 = $this->broker->findPage(perPage: 3, page: 1);
        $page2 = $this->broker->findPage(perPage: 3, page: 2);
        $page3 = $this->broker->findPage(perPage: 3, page: 3);

        self::assertCount(3, $page1);
        self::assertCount(3, $page2);
        self::assertCount(1, $page3);

        self::assertSame('IMM-07', $page1[0]->imm_id);
        self::assertSame('IMM-04', $page2[0]->imm_id);
        self::assertSame('IMM-01', $page3[0]->imm_id);
    }

    public function testCountAllRespectsFilters(): void
    {
        $this->seed('IMM-A', 'address');
        $this->seed('IMM-B', 'bytecode');
        $this->seed('IMM-C', 'address');

        self::assertSame(3, $this->broker->countAll());
        self::assertSame(2, $this->broker->countAll(types: ['address']));
        self::assertSame(1, $this->broker->countAll(types: ['bytecode']));
    }

    public function testCountByStatus(): void
    {
        $this->seed('IMM-A', status: 'active');
        $this->seed('IMM-B', status: 'active');
        $this->seed('IMM-C', status: 'expired');

        $counts = $this->broker->countByStatus();
        self::assertSame(2, $counts['active']);
        self::assertSame(1, $counts['expired']);
        self::assertSame(0, $counts['slashed']);
        self::assertSame(0, $counts['challenged']);
    }

    public function testCountByVerdict(): void
    {
        $this->seed('IMM-A', verdict: 'malicious');
        $this->seed('IMM-B', verdict: 'suspicious');
        $this->seed('IMM-C', verdict: 'malicious');

        $counts = $this->broker->countByVerdict();
        self::assertSame(2, $counts['malicious']);
        self::assertSame(1, $counts['suspicious']);
    }

    private function seed(
        string $immId,
        string $type = 'address',
        string $verdict = 'malicious',
        int $severity = 70,
        string $status = 'active',
        ?string $publisherEns = null,
    ): int {
        $stub = '\\x' . hash('sha256', $immId);
        return $this->broker->insert([
            'keccak_id'         => $stub,
            'imm_id'            => $immId,
            'type'              => $type,
            'verdict'           => $verdict,
            'confidence'        => 80,
            'severity'          => $severity,
            'status'            => $status,
            'primary_matcher'   => '{}',
            'context_hash'      => $stub,
            'evidence_cid'      => $stub,
            'stake_lock_until'  => '2026-12-31 00:00:00+00',
            'publisher'         => '\\x' . substr(hash('sha256', $immId), 0, 40),
            'publisher_ens'     => $publisherEns,
            'stake_amount'      => '1.000000',
            'attestation'       => $stub,
        ]);
    }
}
