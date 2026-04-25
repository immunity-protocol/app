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
