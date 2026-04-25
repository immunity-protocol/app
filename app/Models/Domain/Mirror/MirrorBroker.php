<?php

declare(strict_types=1);

namespace App\Models\Domain\Mirror;

use Zephyrus\Data\Broker;

final class MirrorBroker extends Broker
{
    /**
     * @return Mirror[]
     */
    public function findByAntibodyId(int $antibodyId): array
    {
        $rows = $this->select(
            "SELECT * FROM mirrors WHERE antibody_id = ? ORDER BY mirrored_at DESC",
            [$antibodyId]
        );
        return array_map(Mirror::fromRow(...), $rows);
    }

    public function countActive(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM mirrors WHERE status = 'active'"
        );
    }

    /**
     * @return array<string, int> map of chain_name -> active mirror count
     */
    public function countActiveByChain(): array
    {
        $rows = $this->select(
            "SELECT chain_name, count(*) AS n
             FROM mirrors WHERE status = 'active' GROUP BY chain_name"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r->chain_name] = (int) $r->n;
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
        $sql = "INSERT INTO mirrors ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
