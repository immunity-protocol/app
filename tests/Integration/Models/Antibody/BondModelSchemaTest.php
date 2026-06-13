<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Antibody;

use Tests\IntegrationTestCase;

/**
 * Guards the bond-model schema migration: the fresh build (structure.sql, the
 * same SQL the live alter-*.sql files converge to) must carry the new bond,
 * escrow, reputation and identity columns, drop the old stake columns, and
 * extend the entry_status enum with 'probation'.
 */
final class BondModelSchemaTest extends IntegrationTestCase
{
    /** @return string[] */
    private function columns(string $schema, string $table): array
    {
        $rows = $this->db->query(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
            [$schema, $table]
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    public function testEntryHasBondModelColumns(): void
    {
        $cols = $this->columns('antibody', 'entry');
        foreach (['bond_amount', 'escrowed_fees', 'matured_at', 'is_seeded', 'prominence_tier', 'corroboration_count'] as $c) {
            self::assertContains($c, $cols, "entry should have $c");
        }
    }

    public function testEntryDroppedStakeColumns(): void
    {
        $cols = $this->columns('antibody', 'entry');
        self::assertNotContains('stake_amount', $cols);
        self::assertNotContains('stake_lock_until', $cols);
    }

    public function testPublisherHasReputationColumns(): void
    {
        $cols = $this->columns('antibody', 'publisher');
        foreach (['score', 'matured_count', 'challenges_won', 'slashed_count', 'genesis_granted',
                  'ens_node', 'registration_bond', 'registered_at', 'deregistered'] as $c) {
            self::assertContains($c, $cols, "publisher should have $c");
        }
    }

    public function testEntryStatusEnumHasProbation(): void
    {
        $values = $this->db->query(
            "SELECT e.enumlabel
               FROM pg_type t
               JOIN pg_enum e ON e.enumtypid = t.oid
               JOIN pg_namespace n ON n.oid = t.typnamespace
              WHERE n.nspname = 'antibody' AND t.typname = 'entry_status'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('probation', array_map('strval', $values));
    }
}
