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
}
