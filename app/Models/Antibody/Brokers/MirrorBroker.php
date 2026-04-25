<?php

declare(strict_types=1);

namespace App\Models\Antibody\Brokers;

use App\Models\Core\Broker;

class MirrorBroker extends Broker
{
    /**
     * @return \stdClass[]
     */
    public function findByEntryId(int $entryId): array
    {
        return $this->select(
            "SELECT * FROM antibody.mirror WHERE entry_id = ? ORDER BY mirrored_at DESC",
            [$entryId]
        );
    }

    public function countActive(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.mirror WHERE status = 'active'"
        );
    }

    /**
     * @return array<string, int> map of chain_name -> active mirror count
     */
    public function countActiveByChain(): array
    {
        $rows = $this->select(
            "SELECT chain_name, count(*) AS n
             FROM antibody.mirror WHERE status = 'active' GROUP BY chain_name"
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
        $sql = "INSERT INTO antibody.mirror ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }
}
