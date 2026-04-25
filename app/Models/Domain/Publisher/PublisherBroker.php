<?php

declare(strict_types=1);

namespace App\Models\Domain\Publisher;

use Zephyrus\Data\Broker;

final class PublisherBroker extends Broker
{
    public function findByAddress(string $addressHex): ?Publisher
    {
        $row = $this->selectOne(
            "SELECT * FROM publishers WHERE address = decode(?, 'hex')",
            [$addressHex]
        );
        return $row === null ? null : Publisher::fromRow($row);
    }

    /**
     * @return Publisher[] ordered by antibodies_published descending
     */
    public function findTopByAntibodies(int $limit): array
    {
        $rows = $this->select(
            "SELECT * FROM publishers
             ORDER BY antibodies_published DESC, last_active_at DESC
             LIMIT ?",
            [$limit]
        );
        return array_map(Publisher::fromRow(...), $rows);
    }

    public function countAll(): int
    {
        return (int) $this->selectValue("SELECT count(*) FROM publishers");
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
        $sql = "INSERT INTO publishers ($colList) VALUES ($placeholders)
                ON CONFLICT (address) DO UPDATE SET $updateClause";
        $this->db->query($sql, array_values($data));
    }
}
