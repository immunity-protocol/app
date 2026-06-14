<?php

declare(strict_types=1);

namespace App\Models\Antibody\Brokers;

use App\Models\Core\Broker;
use stdClass;

/**
 * Reads the protected set (antibody.protected_target) — the curated blue-chip
 * safety rail — and the history of attempts to flag a protected address (the
 * autoimmune / DoS attack). A flag on a protected target can never hard-block
 * (advisory max) and is an obvious false positive, so the challenge game strikes
 * it down: this broker surfaces both the rail and the defences holding.
 */
class ProtectedTargetBroker extends Broker
{
    /**
     * True iff the given lowercase, 0x-stripped address hex is a currently
     * protected target. Protected members can never be hard-blocked.
     */
    public function isProtected(string $addressHex): bool
    {
        $row = $this->selectOne(
            "SELECT 1 AS hit FROM antibody.protected_target
              WHERE address = decode(?, 'hex') AND protected = true",
            [$addressHex]
        );
        return $row !== null;
    }

    public function countProtected(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.protected_target WHERE protected = true"
        );
    }

    /**
     * Attempts to flag a protected address. Keyed on the target being in the
     * protected set (the source of truth) rather than the entry's prominence_tier
     * column, which is only set once evidence hydration resolves the matcher.
     */
    private const PROTECTED_FLAG_PREDICATE =
        "decode(substr(e.primary_matcher->>'target', 3), 'hex')
            IN (SELECT address FROM antibody.protected_target WHERE protected)";

    /** Total recorded attempts to flag a protected address. */
    public function countAttacks(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.entry e WHERE " . self::PROTECTED_FLAG_PREDICATE
        );
    }

    /** Attempts that have been struck down (slashed) — defences that held. */
    public function countDefeated(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.entry e
              WHERE e.status = 'slashed' AND " . self::PROTECTED_FLAG_PREDICATE
        );
    }

    /**
     * Each protected address with how many times it's been falsely flagged and
     * how many of those flags were struck down.
     *
     * @return stdClass[] rows: { address, attacks, defeated, updated_at }
     */
    public function findProtectedWithStats(): array
    {
        return $this->select(
            "SELECT '0x' || encode(pt.address, 'hex')                         AS address,
                    coalesce(a.attacks, 0)                                    AS attacks,
                    coalesce(a.defeated, 0)                                   AS defeated,
                    pt.updated_at
               FROM antibody.protected_target pt
          LEFT JOIN (
                    SELECT decode(substr(e.primary_matcher->>'target', 3), 'hex') AS target,
                           count(*)                                          AS attacks,
                           count(*) FILTER (WHERE e.status = 'slashed')      AS defeated
                      FROM antibody.entry e
                     WHERE e.prominence_tier >= 1
                  GROUP BY 1
                    ) a ON a.target = pt.address
              WHERE pt.protected = true
           ORDER BY attacks DESC, pt.updated_at ASC"
        );
    }

    /**
     * Recent attempts to flag a protected address, with the publisher identity
     * and the challenge state/outcome.
     *
     * @return stdClass[] rows: { imm_id, keccak_id, target, status, confidence,
     *   severity, publisher, publisher_ens, created_at, challenge_status,
     *   is_invalid }
     */
    public function findFlagAttempts(int $limit = 40): array
    {
        return $this->select(
            "SELECT e.imm_id,
                    '0x' || encode(e.keccak_id, 'hex')      AS keccak_id,
                    e.primary_matcher->>'target'            AS target,
                    e.status,
                    e.confidence,
                    e.severity,
                    '0x' || encode(e.publisher, 'hex')      AS publisher,
                    p.ens                                    AS publisher_ens,
                    e.created_at,
                    c.status                                 AS challenge_status,
                    c.is_invalid
               FROM antibody.entry e
          LEFT JOIN antibody.publisher p ON p.address = e.publisher
          LEFT JOIN antibody.challenge c ON c.keccak_id = e.keccak_id
              WHERE " . self::PROTECTED_FLAG_PREDICATE . "
           ORDER BY e.created_at DESC
              LIMIT ?",
            [$limit]
        );
    }
}
