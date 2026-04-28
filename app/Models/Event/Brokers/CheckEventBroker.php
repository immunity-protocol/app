<?php

declare(strict_types=1);

namespace App\Models\Event\Brokers;

use App\Models\Core\Broker;

class CheckEventBroker extends Broker
{
    public function countSince(string $sinceIso): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.check_event WHERE occurred_at >= ?::timestamptz",
            [$sinceIso]
        );
    }

    /**
     * @return \stdClass[]  rows: { agent_id, occurred_at, cache_hit }
     */
    public function findRecentSince(string $sinceIso, int $limit = 200): array
    {
        return $this->select(
            "SELECT agent_id, occurred_at, cache_hit
               FROM event.check_event
              WHERE occurred_at > ?::timestamptz
           ORDER BY occurred_at ASC
              LIMIT ?",
            [$sinceIso, $limit]
        );
    }

    public function countCacheHitsSince(string $sinceIso): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.check_event
             WHERE cache_hit = true AND occurred_at >= ?::timestamptz",
            [$sinceIso]
        );
    }

    public function countTeeRoundTripsSince(string $sinceIso): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.check_event
             WHERE tee_used = true AND occurred_at >= ?::timestamptz",
            [$sinceIso]
        );
    }

    /**
     * @return array<string, int> map of decision -> count
     */
    public function countByDecisionSince(string $sinceIso): array
    {
        $rows = $this->select(
            "SELECT decision::text AS decision, count(*) AS n
             FROM event.check_event WHERE occurred_at >= ?::timestamptz
             GROUP BY decision",
            [$sinceIso]
        );
        $out = ['allow' => 0, 'block' => 0, 'escalate' => 0];
        foreach ($rows as $r) {
            $out[$r->decision] = (int) $r->n;
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
        $sql = "INSERT INTO event.check_event ($colList) VALUES ($placeholders) RETURNING id";
        $row = $this->selectOne($sql, array_values($data));
        return (int) $row->id;
    }

    /**
     * Record value-at-risk on every check_event matching the given tx_hash,
     * and propagate the same value into event.block_event.value_protected_usd
     * (when a block landed for that check). Returns the number of check_event
     * rows updated; 0 means we accepted the report but found no matching tx
     * (out-of-order: the report arrived before the indexer ingested it).
     *
     * Idempotent: setting the same value twice is a no-op DB-wise.
     */
    public function setValueAtRisk(string $txHashHex, string $valueUsd): int
    {
        $txHashHex = strtolower($txHashHex);
        if (str_starts_with($txHashHex, '0x')) {
            $txHashHex = substr($txHashHex, 2);
        }
        $bytea = '\\x' . $txHashHex;

        $stmt = $this->db->query(
            "UPDATE event.check_event
                SET value_at_risk_usd = ?::numeric(20, 6)
              WHERE tx_hash = ?",
            [$valueUsd, $bytea]
        );
        $updated = $stmt->rowCount();

        $this->db->query(
            "UPDATE event.block_event
                SET value_protected_usd = ?::numeric(20, 6)
              WHERE tx_hash = ?",
            [$valueUsd, $bytea]
        );

        return $updated;
    }
}
