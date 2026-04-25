<?php

declare(strict_types=1);

namespace App\Models\Domain\Antibody;

use Zephyrus\Data\Broker;

final class AntibodyBroker extends Broker
{
    public function findById(int $id): ?Antibody
    {
        $row = $this->selectOne("SELECT * FROM antibodies WHERE id = ?", [$id]);
        return $row === null ? null : Antibody::fromRow($row);
    }

    public function findByImmId(string $immId): ?Antibody
    {
        $row = $this->selectOne("SELECT * FROM antibodies WHERE imm_id = ?", [$immId]);
        return $row === null ? null : Antibody::fromRow($row);
    }

    /**
     * Most recent antibodies first. `$beforeId` enables keyset pagination.
     *
     * @return Antibody[]
     */
    public function findRecent(int $limit, ?int $beforeId = null): array
    {
        if ($beforeId !== null) {
            $rows = $this->select(
                "SELECT * FROM antibodies WHERE id < ? ORDER BY id DESC LIMIT ?",
                [$beforeId, $limit]
            );
        } else {
            $rows = $this->select(
                "SELECT * FROM antibodies ORDER BY id DESC LIMIT ?",
                [$limit]
            );
        }
        return array_map(Antibody::fromRow(...), $rows);
    }

    public function countActive(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibodies WHERE status = 'active'"
        );
    }

    /**
     * @return array<string, int> map of antibody_type -> count
     */
    public function countByType(): array
    {
        $rows = $this->select(
            "SELECT type, count(*) AS n FROM antibodies GROUP BY type"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r->type] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Insert a row from a flat associative array. Returns the new id.
     *
     * @param array<string, mixed> $data must contain every NOT-NULL column.
     */
    public function insert(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn ($c) => '"' . $c . '"', $cols));
        $sql = "INSERT INTO antibodies ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
