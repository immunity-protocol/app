<?php

declare(strict_types=1);

namespace App\Models\Antibody\Brokers;

use App\Models\Core\Broker;
use stdClass;

class EntryBroker extends Broker
{
    public function findById(int $id): ?stdClass
    {
        return $this->selectOne("SELECT * FROM antibody.entry WHERE id = ?", [$id]);
    }

    public function findByImmId(string $immId): ?stdClass
    {
        return $this->selectOne("SELECT * FROM antibody.entry WHERE imm_id = ?", [$immId]);
    }

    /**
     * Tier-2 lookup: resolve an antibody by its on-chain primary matcher hash.
     * Mirrors the contract's `getAntibodyByMatcherHash` so the SDK and the
     * explorer agree on the indexed envelope.
     *
     * @param string $hashHex 0x-prefixed 32-byte hex (66 chars) or bare 64 hex.
     */
    public function findByPrimaryMatcherHash(string $hashHex): ?stdClass
    {
        $stripped = $hashHex;
        if (str_starts_with($stripped, '0x') || str_starts_with($stripped, '0X')) {
            $stripped = substr($stripped, 2);
        }
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $stripped)) {
            return null;
        }
        return $this->selectOne(
            "SELECT * FROM antibody.entry WHERE primary_matcher_hash = decode(?, 'hex')",
            [strtolower($stripped)]
        );
    }

    /**
     * @return stdClass[]
     */
    public function findRecent(int $limit, ?int $beforeId = null): array
    {
        if ($beforeId !== null) {
            return $this->select(
                "SELECT * FROM antibody.entry WHERE id < ? ORDER BY id DESC LIMIT ?",
                [$beforeId, $limit]
            );
        }
        return $this->select(
            "SELECT * FROM antibody.entry ORDER BY id DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * Recent published entries pre-joined with cache_hits, mirror_count,
     * value_protected_usd, and last_block_at. Drives the dashboard's active
     * registry table (latest first).
     *
     * @return stdClass[]
     */
    public function findRecentWithStats(int $limit = 10): array
    {
        return $this->selectStatsRows('e.id DESC', $limit);
    }

    /**
     * Same shape as findRecentWithStats but ordered by cache hits desc.
     * Drives the landing page's "top attacks" table.
     *
     * @return stdClass[]
     */
    public function findTopByCacheHits(int $limit = 10): array
    {
        return $this->selectStatsRows('cache_hits DESC, e.id DESC', $limit);
    }

    /**
     * Shared SELECT that joins the per-entry counts/sums; orderClause is a
     * pre-validated ORDER BY fragment, NOT user input.
     *
     * @return stdClass[]
     */
    private function selectStatsRows(string $orderClause, int $limit): array
    {
        // Scalar subqueries instead of multi-LEFT-JOIN + GROUP BY: joining
        // three independent collections (check_event, block_event, mirror)
        // produces a cartesian blowup and double-counts SUM/MAX. Subqueries
        // give correct per-entry aggregates regardless of cardinality.
        $sql = "
            SELECT
                e.id, e.imm_id, e.type::text AS type, e.verdict::text AS verdict,
                e.redacted_reasoning, e.publisher_ens, e.created_at,
                encode(e.publisher, 'hex') AS publisher_hex,
                (SELECT count(*) FROM event.check_event ce
                  WHERE ce.matched_entry_id = e.id AND ce.cache_hit = true) AS cache_hits,
                (SELECT count(*) FROM event.block_event be
                  WHERE be.entry_id = e.id)                                 AS block_count,
                (SELECT count(*) FROM antibody.mirror am
                  WHERE am.entry_id = e.id AND am.status = 'active')        AS mirror_count,
                (SELECT COALESCE(SUM(be.value_protected_usd), 0)
                   FROM event.block_event be
                  WHERE be.entry_id = e.id)::text                           AS value_protected_usd,
                (SELECT MAX(be.occurred_at) FROM event.block_event be
                  WHERE be.entry_id = e.id)                                 AS last_block_at
              FROM antibody.entry e
             ORDER BY $orderClause
             LIMIT ?";
        return $this->select($sql, [$limit]);
    }

    public function countActive(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.entry WHERE status = 'active'"
        );
    }

    /**
     * Filtered list for the explorer.
     *
     * @return stdClass[]
     */
    public function findFiltered(
        ?string $type = null,
        ?string $status = null,
        ?string $search = null,
        int $limit = 30,
        ?int $beforeId = null,
    ): array {
        $where = ['1=1'];
        $params = [];
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?::antibody.entry_type';
            $params[] = $type;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?::antibody.entry_status';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $where[] = '(imm_id ILIKE ? OR publisher_ens ILIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($beforeId !== null) {
            $where[] = 'id < ?';
            $params[] = $beforeId;
        }
        $params[] = $limit;
        $sql = "SELECT * FROM antibody.entry WHERE " . implode(' AND ', $where)
             . " ORDER BY id DESC LIMIT ?";
        return $this->select($sql, $params);
    }

    public function countFiltered(
        ?string $type = null,
        ?string $status = null,
        ?string $search = null,
    ): int {
        $where = ['1=1'];
        $params = [];
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?::antibody.entry_type';
            $params[] = $type;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?::antibody.entry_status';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $where[] = '(imm_id ILIKE ? OR publisher_ens ILIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $sql = "SELECT count(*) FROM antibody.entry WHERE " . implode(' AND ', $where);
        return (int) $this->selectValue($sql, $params);
    }

    /**
     * @return array<string, int> map of type -> count
     */
    public function countByType(): array
    {
        $rows = $this->select(
            "SELECT type::text AS type, count(*) AS n FROM antibody.entry GROUP BY type"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r->type] = (int) $r->n;
        }
        return $out;
    }

    /**
     * @return array<string, int> map of status -> count
     */
    public function countByStatus(): array
    {
        $rows = $this->select(
            "SELECT status::text AS status, count(*) AS n FROM antibody.entry GROUP BY status"
        );
        $out = ['active' => 0, 'challenged' => 0, 'expired' => 0, 'slashed' => 0];
        foreach ($rows as $r) {
            $out[$r->status] = (int) $r->n;
        }
        return $out;
    }

    /**
     * @return array<string, int> map of verdict -> count
     */
    public function countByVerdict(): array
    {
        $rows = $this->select(
            "SELECT verdict::text AS verdict, count(*) AS n FROM antibody.entry GROUP BY verdict"
        );
        $out = ['malicious' => 0, 'suspicious' => 0];
        foreach ($rows as $r) {
            $out[$r->verdict] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Per-antibody network-impact metrics for the detail page. Reads from
     * event.check_event and event.block_event referencing this entry id.
     *
     * @return array{
     *   cache_hits: int,
     *   agents_synced: int,
     *   blocks_made: int,
     *   value_protected_usd: string,
     *   publisher_earnings_usdc: string,
     *   ingestion: list<int>
     * }
     */
    public function impactFor(int $entryId): array
    {
        return [
            'cache_hits' => (int) $this->selectValue(
                "SELECT count(*) FROM event.check_event
                  WHERE matched_entry_id = ? AND cache_hit = true",
                [$entryId]
            ),
            'agents_synced' => (int) $this->selectValue(
                "SELECT count(DISTINCT agent_id) FROM event.check_event
                  WHERE matched_entry_id = ?",
                [$entryId]
            ),
            'blocks_made' => (int) $this->selectValue(
                "SELECT count(*) FROM event.block_event WHERE entry_id = ?",
                [$entryId]
            ),
            'value_protected_usd' => (string) ($this->selectValue(
                "SELECT COALESCE(SUM(value_protected_usd), 0)::text
                   FROM event.block_event WHERE entry_id = ?",
                [$entryId]
            ) ?? '0'),
            // Sum of the per-block publisher reward fields (80% of the
            // 0.002 USDC fee per match by default). Always returns a
            // numeric string so the view can format with sub-cent precision.
            'publisher_earnings_usdc' => (string) ($this->selectValue(
                "SELECT COALESCE(SUM(publisher_reward_usdc), 0)::text
                   FROM event.block_event WHERE entry_id = ?",
                [$entryId]
            ) ?? '0'),
            // Pool reverts: subset of blocks_made that came from the
            // Sepolia hook (chain_id 11155111) — the DEX demo. Counted
            // separately so the antibody detail can split the story.
            'pool_reverts' => (int) $this->selectValue(
                "SELECT count(*) FROM event.block_event
                  WHERE entry_id = ? AND chain_id = 11155111",
                [$entryId]
            ),
            // First-check-after-publish latency. Returns null until at least
            // one matching check has been settled. Used by the "Network
            // propagation" stat in the at-a-glance panel.
            'propagation_seconds' => $this->propagationFor($entryId),
            'ingestion' => $this->buildIngestionHistogram($entryId, 30),
        ];
    }

    /**
     * Seconds between antibody publish and the first check_event that
     * matched this entry. `null` when no checks have landed yet, so the UI
     * can show a dash instead of a fake number.
     */
    private function propagationFor(int $entryId): ?float
    {
        $row = $this->selectOne(
            "SELECT EXTRACT(EPOCH FROM (MIN(ce.occurred_at) - e.created_at)) AS secs
               FROM antibody.entry e
               JOIN event.check_event ce ON ce.matched_entry_id = e.id
              WHERE e.id = ?
              GROUP BY e.created_at",
            [$entryId]
        );
        if ($row === null || $row->secs === null) {
            return null;
        }
        $secs = (float) $row->secs;
        // Negative is meaningless (clock skew); clamp to 0.
        return $secs < 0 ? 0.0 : $secs;
    }

    /**
     * @return list<int>
     */
    private function buildIngestionHistogram(int $entryId, int $buckets): array
    {
        $rows = $this->select(
            "WITH e AS (
                SELECT id, created_at FROM antibody.entry WHERE id = ?
            ),
            spans AS (
                SELECT
                    GREATEST(
                        EXTRACT(EPOCH FROM (now() - (SELECT created_at FROM e))) * 1000,
                        1
                    )::bigint AS span_ms
            )
            SELECT
                LEAST(
                    ?::int - 1,
                    GREATEST(0,
                        FLOOR(
                            EXTRACT(EPOCH FROM (c.occurred_at - e.created_at)) * 1000
                            / GREATEST(s.span_ms / ?::int, 1)
                        )::int
                    )
                ) AS bucket,
                count(*) AS n
              FROM event.check_event c
              CROSS JOIN e
              CROSS JOIN spans s
             WHERE c.matched_entry_id = e.id
             GROUP BY bucket
             ORDER BY bucket",
            [$entryId, $buckets, $buckets]
        );

        $out = array_fill(0, $buckets, 0);
        foreach ($rows as $r) {
            $idx = (int) $r->bucket;
            if ($idx >= 0 && $idx < $buckets) {
                $out[$idx] = (int) $r->n;
            }
        }
        return $out;
    }

    /**
     * Page-number paginated, multi-value filter list for the explorer UI.
     *
     * @param array<int, string> $types     antibody.entry_type values
     * @param array<int, string> $statuses  antibody.entry_status values
     * @param array<int, string> $verdicts  antibody.entry_verdict values
     * @param string|null        $range     '24h' | '7d' | '30d' | '90d' | 'all' | null
     * @param string|null        $publisher exact ENS (case-insensitive) or address hex prefix
     * @return stdClass[]
     */
    public function findPage(
        array $types = [],
        array $statuses = [],
        array $verdicts = [],
        ?string $search = null,
        ?string $range = null,
        ?int $sevMin = null,
        ?int $sevMax = null,
        ?string $publisher = null,
        int $perPage = 30,
        int $page = 1,
    ): array {
        [$where, $params] = $this->buildFilterWhere(
            $types, $statuses, $verdicts, $search, $range, $sevMin, $sevMax, $publisher
        );
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;
        $sql = "SELECT * FROM antibody.entry WHERE " . implode(' AND ', $where)
             . " ORDER BY id DESC LIMIT ? OFFSET ?";
        return $this->select($sql, $params);
    }

    /**
     * Count of rows matching the same filter set as findPage().
     *
     * @param array<int, string> $types
     * @param array<int, string> $statuses
     * @param array<int, string> $verdicts
     */
    public function countAll(
        array $types = [],
        array $statuses = [],
        array $verdicts = [],
        ?string $search = null,
        ?string $range = null,
        ?int $sevMin = null,
        ?int $sevMax = null,
        ?string $publisher = null,
    ): int {
        [$where, $params] = $this->buildFilterWhere(
            $types, $statuses, $verdicts, $search, $range, $sevMin, $sevMax, $publisher
        );
        $sql = "SELECT count(*) FROM antibody.entry WHERE " . implode(' AND ', $where);
        return (int) $this->selectValue($sql, $params);
    }

    /**
     * Shared WHERE builder for findPage / countAll. Keeps SQL composition in
     * one place and unsanctioned enum values out of the SQL.
     *
     * @param array<int, string> $types
     * @param array<int, string> $statuses
     * @param array<int, string> $verdicts
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function buildFilterWhere(
        array $types,
        array $statuses,
        array $verdicts,
        ?string $search,
        ?string $range,
        ?int $sevMin,
        ?int $sevMax,
        ?string $publisher,
    ): array {
        $where = ['1=1'];
        $params = [];

        if ($types !== []) {
            $placeholders = implode(', ', array_fill(0, count($types), '?::antibody.entry_type'));
            $where[] = "type IN ($placeholders)";
            foreach ($types as $t) {
                $params[] = $t;
            }
        }
        if ($statuses !== []) {
            $placeholders = implode(', ', array_fill(0, count($statuses), '?::antibody.entry_status'));
            $where[] = "status IN ($placeholders)";
            foreach ($statuses as $s) {
                $params[] = $s;
            }
        }
        if ($verdicts !== []) {
            $placeholders = implode(', ', array_fill(0, count($verdicts), '?::antibody.entry_verdict'));
            $where[] = "verdict IN ($placeholders)";
            foreach ($verdicts as $v) {
                $params[] = $v;
            }
        }
        if ($search !== null && $search !== '') {
            $where[] = '(imm_id ILIKE ? OR publisher_ens ILIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $rangeSql = match ($range) {
            '24h' => "created_at >= now() - interval '24 hours'",
            '7d'  => "created_at >= now() - interval '7 days'",
            '30d' => "created_at >= now() - interval '30 days'",
            '90d' => "created_at >= now() - interval '90 days'",
            default => null,
        };
        if ($rangeSql !== null) {
            $where[] = $rangeSql;
        }
        if ($sevMin !== null) {
            $where[] = 'severity >= ?';
            $params[] = max(0, min(100, $sevMin));
        }
        if ($sevMax !== null) {
            $where[] = 'severity <= ?';
            $params[] = max(0, min(100, $sevMax));
        }
        if ($publisher !== null && $publisher !== '') {
            $publisher = trim($publisher);
            if (str_starts_with($publisher, '0x') || str_starts_with($publisher, '0X')) {
                $hex = substr($publisher, 2);
                $where[] = "encode(publisher, 'hex') ILIKE ?";
                $params[] = strtolower($hex) . '%';
            } else {
                $where[] = 'publisher_ens ILIKE ?';
                $params[] = $publisher;
            }
        }

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
        $sql = "INSERT INTO antibody.entry ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
