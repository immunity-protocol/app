<?php

declare(strict_types=1);

namespace App\Models\Antibody\Brokers;

use App\Models\Core\Broker;
use stdClass;

class PublisherBroker extends Broker
{
    public function findByAddressHex(string $addressHex): ?stdClass
    {
        return $this->selectOne(
            "SELECT * FROM antibody.publisher WHERE address = decode(?, 'hex')",
            [$addressHex]
        );
    }

    /**
     * @return stdClass[] ordered by antibodies_published desc
     */
    public function findTopByAntibodies(int $limit): array
    {
        return $this->select(
            "SELECT * FROM antibody.publisher
             ORDER BY antibodies_published DESC, last_active_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    public function countAll(): int
    {
        return (int) $this->selectValue("SELECT count(*) FROM antibody.publisher");
    }

    /**
     * Page of publishers with derived totals for the contributors leaderboard.
     *
     * Aggregates `antibody.publisher` (per-publisher counters) with a join
     * onto `event.block_event` so we surface the *value protected* across
     * all of a publisher's antibodies — not just per-publisher earnings,
     * which already live as a column. Sort is "earned-first" by default
     * (the leaderboard intent), with successful_blocks and last_active_at
     * as tie-breakers so two publishers with identical $0 earnings still
     * order deterministically.
     *
     * @return \stdClass[] rows: { address (bytea), ens, antibodies_published,
     *   successful_blocks, total_earned_usdc, total_value_protected_usd,
     *   first_seen_at, last_active_at }
     */
    public function listWithStatsPage(int $offset, int $limit): array
    {
        return $this->select(
            "SELECT p.address, p.ens, p.antibodies_published, p.successful_blocks,
                    p.total_earned_usdc, p.first_seen_at, p.last_active_at,
                    COALESCE((
                        SELECT SUM(b.value_protected_usd)
                          FROM event.block_event b
                          JOIN antibody.entry e ON e.id = b.entry_id
                         WHERE e.publisher = p.address
                    ), 0)::text AS total_value_protected_usd
               FROM antibody.publisher p
           ORDER BY p.total_earned_usdc DESC,
                    p.successful_blocks DESC,
                    p.last_active_at DESC,
                    p.address
              LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): void
    {
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
        $updates = [];
        foreach ($cols as $c) {
            if ($c === 'address' || $c === 'first_seen_at') {
                continue;
            }
            $updates[] = '"' . $c . '" = EXCLUDED."' . $c . '"';
        }
        $updateClause = implode(', ', $updates);
        $sql = "INSERT INTO antibody.publisher ($colList) VALUES ($placeholders)
                ON CONFLICT (address) DO UPDATE SET $updateClause";
        $this->db->query($sql, array_values($data));
    }

    /**
     * Recompute aggregate fields from antibody.entry and event.block_event.
     * Run by the mock orchestrator after entries and blocks have been seeded.
     */
    public function recomputeAggregates(): void
    {
        $this->db->query(
            "UPDATE antibody.publisher p SET
                antibodies_published = coalesce((
                    SELECT count(*) FROM antibody.entry WHERE publisher = p.address
                ), 0),
                successful_blocks = coalesce((
                    SELECT count(*) FROM event.block_event b
                    JOIN antibody.entry e ON b.entry_id = e.id
                    WHERE e.publisher = p.address
                ), 0),
                total_staked_usdc = coalesce((
                    SELECT sum(stake_amount) FROM antibody.entry
                    WHERE publisher = p.address
                ), 0),
                last_active_at = coalesce((
                    SELECT max(created_at) FROM antibody.entry
                    WHERE publisher = p.address
                ), p.first_seen_at)"
        );
    }
}
