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
     * Returns every entry sharing the matcher hash — corroboration lets several
     * distinct publishers each mint their own antibody for the same matcher.
     *
     * @param string $hashHex 0x-prefixed 32-byte hex (66 chars) or bare 64 hex.
     * @return stdClass[] ordered oldest-first
     */
    public function findAllByPrimaryMatcherHash(string $hashHex): array
    {
        $stripped = $hashHex;
        if (str_starts_with($stripped, '0x') || str_starts_with($stripped, '0X')) {
            $stripped = substr($stripped, 2);
        }
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $stripped)) {
            return [];
        }
        return $this->select(
            "SELECT * FROM antibody.entry WHERE primary_matcher_hash = decode(?, 'hex') ORDER BY created_at ASC",
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

    /**
     * Page of antibodies belonging to a single publisher, with the same
     * per-row stats (cache_hits, block_count, mirror_count,
     * value_protected_usd, last_block_at) as the dashboard's active
     * registry table. Ordered newest-first.
     *
     * @param string $publisherBytea the publisher's bytea hex (no `0x` prefix or `\x`).
     * @return stdClass[]
     */
    public function findPageByPublisher(string $publisherBytea, int $offset, int $limit): array
    {
        $sql = "
            SELECT
                e.id, e.imm_id, e.type::text AS type, e.verdict::text AS verdict,
                e.status::text AS status, e.confidence, e.severity,
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
             WHERE e.publisher = decode(?, 'hex')
             ORDER BY e.id DESC
             LIMIT ? OFFSET ?";
        return $this->select($sql, [$publisherBytea, $limit, $offset]);
    }

    public function countByPublisher(string $publisherBytea): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.entry WHERE publisher = decode(?, 'hex')",
            [$publisherBytea]
        );
    }

    public function countActive(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.entry WHERE status = 'active'"
        );
    }

    /**
     * Antibodies created on or after the given ISO timestamp. Drives the
     * "+N in 1h" sub-stat on the landing page tile.
     */
    public function countCreatedSince(string $sinceIso): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.entry WHERE created_at >= ?::timestamptz",
            [$sinceIso]
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
            // Base Sepolia hook (chain_id 84532) — the DEX demo. Counted
            // separately so the antibody detail can split the story.
            'pool_reverts' => (int) $this->selectValue(
                "SELECT count(*) FROM event.block_event
                  WHERE entry_id = ? AND chain_id = 84532",
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
     * Grouped threat listing for the explorer: ONE row per distinct
     * primary_matcher_hash (the CVE-style registry unit), not one per antibody.
     * Corroborating antibodies collapse into a single threat row carrying the
     * threat's sequential id, the count of distinct publishers (the
     * corroboration N/K), the worst severity in the group, the most-enforcing
     * type/verdict, a representative status, and first-seen.
     *
     * The same WHERE filters as the per-antibody list apply to the underlying
     * antibodies before grouping, so facets/filters keep working over the
     * grouped view.
     *
     * @param array<int, string> $types
     * @param array<int, string> $statuses
     * @param array<int, string> $verdicts
     * @return stdClass[]
     */
    public function findThreatPage(
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

        // Newest threat first by first-seen (the earliest antibody's id). The
        // representative type/verdict/severity is the worst case in the group;
        // the representative status is the earliest antibody's. corroboration is
        // distinct publishers among non-terminal antibodies (matches the
        // corroboration_count denormalization the SDK rule reads).
        $sql = "
            SELECT
                t.threat_id,
                t.threat_seq,
                encode(e.primary_matcher_hash, 'hex')                AS matcher_hash_hex,
                min(e.id)                                            AS first_entry_id,
                (array_agg(e.imm_id ORDER BY e.id))[1]               AS first_imm_id,
                (array_agg(e.type::text ORDER BY e.severity DESC, e.id))[1]    AS type,
                (array_agg(e.verdict::text ORDER BY (e.verdict = 'malicious') DESC, e.id))[1] AS verdict,
                (array_agg(e.status::text ORDER BY e.id))[1]         AS status,
                max(e.severity)                                      AS severity,
                max(e.is_seeded)                                     AS is_seeded,
                count(DISTINCT e.publisher) FILTER (
                    WHERE e.status NOT IN ('slashed'::antibody.entry_status, 'expired'::antibody.entry_status)
                )                                                    AS corroboration,
                count(*)                                             AS antibody_count,
                min(e.created_at)                                    AS first_seen_at
              FROM antibody.entry e
              JOIN antibody.threat t ON t.matcher_hash = e.primary_matcher_hash
             WHERE " . implode(' AND ', $where) . "
               AND e.primary_matcher_hash IS NOT NULL
             GROUP BY t.threat_id, t.threat_seq, e.primary_matcher_hash
             ORDER BY min(e.id) DESC
             LIMIT ? OFFSET ?";
        return $this->select($sql, $params);
    }

    /**
     * Count of distinct threats (matcher hashes) matching the filter set — the
     * grouped-list total used for pagination.
     *
     * @param array<int, string> $types
     * @param array<int, string> $statuses
     * @param array<int, string> $verdicts
     */
    public function countThreats(
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
        $sql = "SELECT count(DISTINCT primary_matcher_hash) FROM antibody.entry
                 WHERE " . implode(' AND ', $where) . " AND primary_matcher_hash IS NOT NULL";
        return (int) $this->selectValue($sql, $params);
    }

    /**
     * The threat for a given matcher hash (bare 64-hex or 0x-prefixed). Used to
     * resolve a threat-centric detail page from a matcher.
     */
    public function findThreatByMatcherHash(string $hashHex): ?stdClass
    {
        $stripped = $hashHex;
        if (str_starts_with($stripped, '0x') || str_starts_with($stripped, '0X')) {
            $stripped = substr($stripped, 2);
        }
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $stripped)) {
            return null;
        }
        return $this->selectOne(
            "SELECT threat_id, threat_seq, encode(matcher_hash, 'hex') AS matcher_hash_hex, first_seen_at
               FROM antibody.threat WHERE matcher_hash = decode(?, 'hex')",
            [strtolower($stripped)]
        );
    }

    /**
     * The threat by its CVE-style id (IMM-T-YYYY-NNNN). Used to route the
     * threat-centric detail page.
     */
    public function findThreatByThreatId(string $threatId): ?stdClass
    {
        return $this->selectOne(
            "SELECT threat_id, threat_seq, encode(matcher_hash, 'hex') AS matcher_hash_hex, first_seen_at
               FROM antibody.threat WHERE threat_id = ?",
            [$threatId]
        );
    }

    /**
     * The threat a given antibody (by imm_id) belongs to, resolved through its
     * matcher hash. Lets the antibody route fall through to the threat view.
     */
    public function findThreatByImmId(string $immId): ?stdClass
    {
        return $this->selectOne(
            "SELECT t.threat_id, t.threat_seq, encode(t.matcher_hash, 'hex') AS matcher_hash_hex, t.first_seen_at
               FROM antibody.entry e
               JOIN antibody.threat t ON t.matcher_hash = e.primary_matcher_hash
              WHERE e.imm_id = ?",
            [$immId]
        );
    }

    /**
     * Per-facet DISTINCT-threat counts for the grouped explorer. Each map value
     * is the number of distinct matcher hashes that have at least one antibody
     * with the facet value — so the sidebar tallies threats, not antibodies.
     *
     * @return array<string, int>
     */
    public function countThreatsByType(): array
    {
        return $this->threatFacet('type');
    }

    /** @return array<string, int> */
    public function countThreatsByStatus(): array
    {
        $out = ['active' => 0, 'probation' => 0, 'challenged' => 0, 'expired' => 0, 'slashed' => 0];
        return array_merge($out, $this->threatFacet('status'));
    }

    /** @return array<string, int> */
    public function countThreatsByVerdict(): array
    {
        $out = ['malicious' => 0, 'suspicious' => 0];
        return array_merge($out, $this->threatFacet('verdict'));
    }

    /**
     * @param 'type'|'status'|'verdict' $column pre-validated enum column name
     * @return array<string, int>
     */
    private function threatFacet(string $column): array
    {
        $rows = $this->select(
            "SELECT {$column}::text AS k, count(DISTINCT primary_matcher_hash) AS n
               FROM antibody.entry
              WHERE primary_matcher_hash IS NOT NULL
              GROUP BY {$column}"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r->k] = (int) $r->n;
        }
        return $out;
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
