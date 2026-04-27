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
